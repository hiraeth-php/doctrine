<?php

namespace Hiraeth\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\Common\Collections;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

use RuntimeException;
use InvalidArgumentException;
use ReflectionException;
use ReflectionProperty;
use ReflectionClass;

/**
 *
 */
class Hydrator
{
	/**
	 *
	 */
	protected static $reflections = [];


	/**
	 *
	 */
	protected $filters = array();


	/**
	 *
	 */
	public function __construct(ManagerRegistry $registry)
	{
		$this->registry = $registry;
	}

	/**
	 *
	 */
	public function addFilter($type, callable $filter)
	{
		$this->filters[$type] = $filter;
	}

	/**
	 *
	 */
	public function fill($entity, array $data, bool $protect = TRUE): Hydrator
	{
		$class     = get_class($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$platform  = $manager->getConnection()->getDatabasePlatform();
		$meta_data = $manager->getClassMetaData($class);

		foreach ($data as $field => $value) {
			if ($protect && array_intersect(['*', $field], $entity::$_protect ?? ['*'])) {
				continue;
			}

			if (array_key_exists($field, $meta_data->embeddedClasses)) {
				$embeddable = $this->reflectProperty($entity, $field)->getValue($entity);

				if (!$embeddable) {
					$embeddable = new $meta_data->embeddedClasses[$field]['class']();
					$this->fillProperty($entity, $field, $embeddable);
				}

				foreach ($value as $embedded_field => $embedded_value) {
					$embed = [$field . '.' . $embedded_field => $embedded_value];

					$this->fill($entity, $embed, $protect);
				}

			} elseif (array_key_exists($field, $meta_data->fieldMappings)) {
				if (is_scalar($value) || is_object($value)) {
					$type  = Type::getType($meta_data->fieldMappings[$field]['type'] ?? 'string');

					if (isset($this->filters[$type->getName()])) {
						$value = $this->filters[$type->getName()]($value);
					} else {
						$value = $type->convertToPHPValue($value, $platform);
					}
				}

				$this->fillProperty($entity, $field, $value);

			} elseif (array_key_exists($field, $meta_data->associationMappings)) {
				$this->fillAssociation($entity, $field, $value, $protect);

			}  else {
				$this->fillProperty($entity, $field, $value);

			}
		}

		return $this;
	}


	/**
	 *
	 */
	protected function fillAssociation(object $entity, string $field, $value, bool $protect = TRUE)
	{
		$class     = get_class($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$meta_data = $manager->getClassMetaData($class);
		$mapping   = $meta_data->associationMappings[$field];

		switch ($mapping['type']) {
			case ClassMetadataInfo::ONE_TO_ONE:
			case ClassMetadataInfo::MANY_TO_ONE:
				$this->fillAssociationToOne($entity, $field, $value, $protect);
				break;

			case ClassMetadataInfo::ONE_TO_MANY:
			case ClassMetadataInfo::MANY_TO_MANY:
				$this->fillAssociationToMany($entity, $field, $value, $protect);
				break;

			default:
				throw new RuntimeException(sprintf(
					'Unknown mapping type "%s"',
					$mapping['type']
				));
		}
	}


	/**
	 *
	 */
	protected function fillAssociationToMany(object $entity, string $field, $values, bool $protect = TRUE): Hydrator
	{
		settype($values, 'array');

		$collection = new Collections\ArrayCollection();

		foreach ($values as $value) {
			$related_entity = $this->findAssociated($entity, $field, $value);

			if ($related_entity) {
				if (is_array($value)) {
					$this->fill($related_entity, $value, $protect);
				}

				$collection->add($related_entity);
			}
		}

		$this->fillProperty($entity, $field, $collection);

		return $this;
	}


	/**
	 *
	 */
	protected function fillAssociationToOne(object $entity, string $field, $value, bool $protect = TRUE): Hydrator
	{
		$related_entity = $this->findAssociated($entity, $field, $value);

		if (is_array($value)) {
			$this->fill($related_entity, $value, $protect);
		}

		$this->fillProperty($entity, $field, $related_entity);

		return $this;
	}


	/**
	 *
	 */
	protected function fillProperty(object $entity, string $name, $value): Hydrator
	{
		if (strpos($name, '.')) {
			$parts = explode('.', $name);
			$name  = array_pop($parts);

			foreach ($parts as $part) {
				if (property_exists($entity, $part)) {
					$entity = $this->reflectProperty($entity, $part)->getValue($entity);
				}
			}
		}

		if (property_exists($entity, $name)) {
			$method   = 'set' . ucwords($name);
			$property = $this->reflectProperty($entity, $name);
			$existing = $property->getValue($entity);

			if ($existing instanceof Collections\Collection) {
				if ($value instanceof Collections\Collection) {
					$value = $value->toArray();
				} else {
					settype($value, 'array');
				}

				foreach ($existing as $i => $entity) {
					if (!in_array($entity, $value, TRUE)) {
						$existing->remove($i);
					}
				}

				foreach ($value as $entity) {
					if (!$existing->contains($entity)) {
						$existing->add($entity);
					}
				}

			} elseif (!is_callable([$entity, $method])) {
				$property->setValue($entity, $value);

			} else {
				$entity->$method($value);

			}
		}

		return $this;
	}


	/**
	 *
	 */
	public function findAssociated($entity, $field, $id, $lock_mode = NULL, $lock_version = NULL)
	{
		if (!is_array($id) && empty($id)) {
			return NULL;
		}

		$class     = get_class($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$meta_data = $manager->getClassMetaData($class);
		$mapping   = $meta_data->getAssociationMapping($field);
		$target    = $mapping['targetEntity'] ?? NULL;

		if (!$class) {
			throw new RuntimeException(
				'Could not determine target entity for field "%s"',
				$field
			);
		}

		$existing_record  = $this->reflectProperty($entity, $field)->getValue($entity);
		$target_meta_data = $manager->getClassMetadata($target);
		$field_names      = $target_meta_data->getIdentifierFieldNames();

		if (is_array($id) || count($field_names) > 1) {
			$id = array_filter(array_intersect_key($id, array_flip($field_names)));
		} else {
			$id = array_filter([$field_names[0] => $id]);
		}

		if (count($id) == count($field_names)) {
			return $manager->find($target, $id, $lock_mode, $lock_version);

		} elseif ($existing_record) {
			return $existing_record;

		} else {
			return new $target();

		}
	}


	/**
	 *
	 */
	protected function reflectProperty(object $entity, $name): ReflectionProperty
	{
		$class = get_class($entity);

		if (!isset(static::$reflections[$class])) {
			static::$reflections[$class]['@'] = new ReflectionClass($class);
		}

		if (!isset(static::$reflections[$class][$name])) {
			try {
				static::$reflections[$class][$name] = static::$reflections[$class]['@']->getProperty($name);
				static::$reflections[$class][$name]->setAccessible(TRUE);

			} catch (ReflectionException $e) {
				throw new InvalidArgumentException(sprintf(
					'Cannot set property, class "%s" has no property named "%s"',
					$class,
					$name
				));
			}
		}

		return static::$reflections[$class][$name];
	}
}

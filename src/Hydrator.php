<?php

namespace Hiraeth\Doctrine;

use Doctrine\DBAL\Types\Type;
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
	public function fill($entity, array $data, bool $protect = TRUE)
	{
		$class          = get_class($entity);
		$this->manager  = $this->registry->getManagerForClass($class);
		$this->metaData = $this->manager->getClassMetaData($class);
		$this->platform = $this->manager->getConnection()->getDatabasePlatform();

		$this->fillProperties($entity, $data, $protect);
	}


	/**
	 *
	 */
	protected function fillAssociation(object $object, string $field, $value)
	{
		$mapping = $this->metaData->associationMappings[$field];

		switch ($mapping['type']) {
			case ClassMetadataInfo::ONE_TO_ONE:
			case ClassMetadataInfo::MANY_TO_ONE:
				$this->fillAssociationToOne($object, $field, $value);
				break;

			case ClassMetadataInfo::ONE_TO_MANY:
			case ClassMetadataInfo::MANY_TO_MANY:
				$this->fillAssociationToMany($object, $field, $value);
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
	protected function fillAssociationToMany(object $object, string $field, $values): Hydrator
	{
		settype($values, 'array');

		$collection = new Collections\ArrayCollection();

		foreach ($values as $value) {
			$related_entity = $this->findAssociated($field, $value);

			if ($related_entity) {
				if (is_array($value)) {
					$this->fill($related_entity, $value);
				}

				$collection->add($related_entity);
			}
		}

		$this->fillProperty($object, $field, $collection);

		return $this;
	}


	/**
	 *
	 */
	protected function fillAssociationToOne(object $object, string $field, $value): Hydrator
	{
		$related_entity = $this->findAssociated($field, $value);

		if (is_array($value)) {
			$this->fill($related_entity, $value);
		}

		$this->fillProperty($object, $field, $related_entity);

		return $this;
	}


	/**
	 *
	 */
	protected function fillProperties(object $object, array $data, bool $protect = TRUE, string $prefix = NULL): Hydrator
	{
		foreach ($data as $field => $value) {
			$full_field = $prefix
				? $prefix . '.' . $field
				: $field;

			if ($protect && array_intersect(['*', $full_field], $object::$_protect ?? ['*'])) {
				continue;
			}

			if (array_key_exists($full_field, $this->metaData->embeddedClasses)) {
				$property   = $this->reflectProperty($object, $field);
				$embeddable = $property->getValue($object);

				if (!$embeddable) {
					$embeddable = new $this->metaData->embeddedClasses[$field]['class']();
					$this->fillProperty($object, $field, $embeddable);
				}

				$this->fillProperties($embeddable, $value, $protect, $full_field);

			} elseif (array_key_exists($full_field, $this->metaData->fieldMappings)) {
				if (is_scalar($value)) {
					$type  = Type::getType($this->metaData->fieldMappings[$full_field]['type'] ?? 'string');

					if (isset($this->filters[$type->getName()])) {
						$value = $this->filters[$type->getName()]($value);
					} else {
						$value = $type->convertToPHPValue($value, $this->platform);
					}

				}

				$this->fillProperty($object, $field, $value);

			} elseif (array_key_exists($field, $this->metaData->associationMappings)) {
				$this->fillAssociation($object, $field, $value);

			}  else {
				$this->fillProperty($object, $field, $value);

			}
		}

		return $this;
	}


	/**
	 *
	 */
	protected function fillProperty(object $object, string $name, $value): Hydrator
	{
		$property = $this->reflectProperty($object, $name);

		if ($value instanceof Collections\Collection) {
			$collection = $property->getValue($name);

			foreach ($collection as $i => $entity) {
				if (!$value->contains($entity)) {
					$collection->remove($i);
				}
			}

			foreach ($value as $entity) {
				if (!$collection->contains($entity)) {
					$collection->add($entity);
				}
			}

		} else {
			$property->setValue($object, $value);
		}

		return $this;
	}


	/**
	 *
	 */
	public function findAssociated($field, $id, $lock_mode = NULL, $lock_version = NULL)
	{
		if ($id === NULL) {
			return NULL;
		}

		$mapping   = $this->metaData->getAssociationMapping($field);
		$class     = $mapping['targetEntity'] ?? NULL;

		if (!$class) {
			throw new RuntimeException(
				'Could not determine target entity for field "%s"',
				$field
			);
		}

		if (is_array($id)) {
			$related_meta_data = $this->manager->getClassMetadata($class);
			$field_names       = $related_meta_data->getIdentifierFieldNames();
			$id                = array_intersect_key($id, array_flip($field_names));
		}

		return $this->manager->find($class, $id, $lock_mode, $lock_version);
	}


	/**
	 *
	 */
	protected function reflectProperty(object $object, $name): ReflectionProperty
	{
		$class = get_class($object);

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

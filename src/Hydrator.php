<?php

namespace Hiraeth\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\Common\Collections;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;
use Doctrine\Persistence\Proxy;
use RuntimeException;

/**
 * @template T of AbstractEntity
 */
class Hydrator
{
	use PropertyAccess;

	/**
	 * @var array<string, callable>
	 */
	protected $filters = array();


	/**
	 * @var ManagerRegistry
	 */
	protected $registry;


	/**
	 *
	 */
	public function __construct(ManagerRegistry $registry)
	{
		$this->registry = $registry;
	}


	/**
	 * @return void
	 */
	public function addFilter(string $type, callable $filter)
	{
		$this->filters[$type] = $filter;
	}


	/**
	 * Fill and object
	 *
	 * @param T $entity
	 * @param array<string, mixed> $data
	 * @return self<T>
	 */
	public function fill(AbstractEntity $entity, array $data, bool $protect = TRUE): Hydrator
	{
		$class     = $this->registry->getClassName($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$platform  = $manager->getConnection()->getDatabasePlatform();
		$meta_data = $manager->getClassMetaData($class);

		if ($entity instanceof Proxy && !$entity->__isInitialized()) {
			$entity->__load();
		}

		foreach ($data as $field => $value) {
			$protected = array_intersect(['*', $field], $entity::$_protect);

			if ($protected) {
				continue;
			}

			if (array_key_exists($field, $meta_data->associationMappings)) {
				$this->fillAssociation($entity, $field, $value, $protect);

			} elseif (array_key_exists($field, $meta_data->embeddedClasses)) {
				$embeddable = $this->getProperty($entity, $field, TRUE);

				if (!$embeddable) {
					$embeddable = new $meta_data->embeddedClasses[$field]['class']();
					$this->setProperty($entity, $field, $embeddable, TRUE);
				}

				foreach ($value as $embedded_field => $embedded_value) {
					$embed = [$field . '.' . $embedded_field => $embedded_value];

					$this->fill($entity, $embed, $protect);
				}

			} elseif (array_key_exists($field, $meta_data->fieldMappings)) {
				if (is_scalar($value) || is_object($value)) {
					$type = Type::getType($meta_data->fieldMappings[$field]['type']);

					if (isset($this->filters[$type->getName()])) {
						$value = $this->filters[$type->getName()]($value);
					} else {
						$value = $type->convertToPHPValue($value, $platform);
					}
				}

				$this->setProperty($entity, $field, $value);

			} else {
				$this->setProperty($entity, $field, $value);

			}
		}

		return $this;
	}


	/**
	 * @param T $entity
	 * @param mixed $value
	 * @return self<T>
	 */
	protected function fillAssociation(AbstractEntity $entity, string $field, $value, bool $protect = TRUE): self
	{
		$link      = NULL;
		$class     = get_class($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$meta_data = $manager->getClassMetaData($class);
		$mapping   = $meta_data->getAssociationMapping($field);

		if ($mapping instanceof OwningSideMapping) {
			$link = $mapping->inversedBy;
		}

		if ($mapping instanceof InverseSideMapping) {
			$link = $mapping->mappedBy;
		}

		if ($mapping->isToOne()) {
			$this->fillAssociationToOne($entity, $field, $link, $value, $protect);
		}

		if ($mapping->isToMany()) {
			$this->fillAssociationToMany($entity, $field, $link, $value, $protect);
		}

		return $this;
	}


	/**
	 * @param T $entity
	 * @param array<mixed> $values
	 * @return self<T>
	 */
	protected function fillAssociationToMany(AbstractEntity $entity, string $field, ?string $link, $values, bool $protect = TRUE): self
	{
		settype($values, 'array');

		$new_collection = new Collection();
		$cur_collection = $this->getProperty($entity, $field, TRUE);

		if (!$cur_collection instanceof Collections\Collection) {
			throw new RuntimeException(sprintf(
				'On "%s" the field "%s" is not a collection, must be initialized as a collection',
				get_class($entity),
				$field
			));
		}

		foreach ($values as $value) {
			$related_entity = $this->findAssociated($entity, $field, $value);

			if ($related_entity) {
				if (is_array($value)) {
					$this->fill($related_entity, $value, $protect);
				}

				$new_collection->add($related_entity);
			}
		}

		foreach ($cur_collection as $related_entity) {
			if (!$new_collection->contains($related_entity)) {
				if ($link) {
					$link_value = $this->getProperty($related_entity, $link, TRUE);

					if ($link_value instanceof Collections\Collection) {
						if ($link_value->contains($entity)) {
							$link_value->removeElement($entity);
						}

					} else {
						$this->setProperty($related_entity, $link, NULL, TRUE);
					}
				}

				$cur_collection->removeElement($related_entity);
			}
		}

		foreach ($new_collection as $related_entity) {
			if (!$cur_collection->contains($related_entity)) {
				if ($link) {
					$link_value = $this->getProperty($related_entity, $link, TRUE);

					if ($link_value instanceof Collections\Collection) {
						if (!$link_value->contains($entity)) {
							$link_value->add($entity);
						}

					} else {
						$this->setProperty($related_entity, $link, $entity, TRUE);
					}
				}

				$cur_collection->add($related_entity);
			}
		}

		return $this;
	}


	/**
	 * @param T $entity
	 * @param mixed $value
	 * @return self<T>
	 */
	protected function fillAssociationToOne(AbstractEntity $entity, string $field, ?string $link, $value, bool $protect = TRUE): self
	{
		$current_value  = $this->getProperty($entity, $field, TRUE);
		$related_entity = $this->findAssociated($entity, $field, $value);

		if (is_array($value)) {
			$this->fill($related_entity, $value, $protect);
		}

		if ($link) {
			//
			// If there's a link and a current value, remove the entity from the current value's
			// link if it's a collection, or set to null if it's a single entity.
			//

			if ($current_value) {
				$link_value = $this->getProperty($current_value, $link, TRUE);

				if ($link_value instanceof Collections\Collection) {
					if ($link_value->contains($entity)) {
						$link_value->removeElement($entity);
					}

				} else {
					$this->setProperty($current_value, $link, NULL, TRUE);
				}
			}

			//
			// If there's a related entity, add the entity to the related entity's link if it's a
			// collection, or set it if it's a single entity.
			//

			if ($related_entity) {
				$link_value = $this->getProperty($related_entity, $link, TRUE);

				if ($link_value instanceof Collections\Collection) {
					if (!$link_value->contains($entity)) {
						$link_value->add($entity);
					}
				} else {
					$this->setProperty($related_entity, $link, $entity, TRUE);
				}
			}
		}

		$this->setProperty($entity, $field, $related_entity, TRUE);

		return $this;
	}


	/**
	 * @param T $entity
	 * @param mixed $id
	 * @param 0|1|2|4|null $lock_mode
	 * @return ?T
	 */
	protected function findAssociated(AbstractEntity $entity, string $field, $id, ?int $lock_mode = NULL, ?int $lock_version = NULL): ?AbstractEntity
	{
		$class     = get_class($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$meta_data = $manager->getClassMetaData($class);
		$mapping   = $meta_data->getAssociationMapping($field);

		if (!$class) {
			throw new RuntimeException(sprintf(
				'Could not determine target entity for field "%s"',
				$field
			));
		}

		/**
		 * @var class-string<T>
		 */
		$target = $mapping->targetEntity;

		//
		// Short circuit logic.  If we're not an array and we're an empty value or existing
		// instance, we can go home early.
		//

		if (!is_array($id)) {
			if (in_array($id, ['', NULL], TRUE)) {
				return NULL;
			}

			if (is_a($id, $target)) {
				return $id;
			}
		}

		$target_meta_data = $manager->getClassMetadata($target);
		$target_id_fields = $target_meta_data->getIdentifierFieldNames();

		//
		// If we have a compound id/primary key, we're going to convert our id to just
		// just those required to identify the record and filter out NULl values.  This will either
		// leave us with enough data to identify the record or an $id that is smaller than the
		// requisite target id fields.  If there is only a singular target id field we're just
		// going to normalize on that name.
		//

		if (!is_scalar($id)) {
			$id = array_filter(array_intersect_key($id, array_flip($target_id_fields)));

		} elseif (count($target_id_fields) == 1) {
			$id = [$target_id_fields[0] => $id];

		} else {
			throw new RuntimeException(sprintf(
				'Invalid associative identity passed, expected compound id, got scalar "%s"',
				print_r($id, TRUE)
			));
		}

		//
		// If our ID has sufficient information we'll try to look up the associated record from
		// the DB in the event it's not associated yet.  Otherwise, we'll fall back to trying
		// to get it from the entity.
		//

		/**
		 * @var AbstractEntity
		 */
		$existing_record = count($id) == count($target_id_fields)
			? $manager->find($target, $id, $lock_mode, $lock_version)
			: $this->getProperty($entity, $field, TRUE)
		;

		//
		// Lastly, if we have an existing record, we'll return that, if not, we'll return a totally
		// new one so that it can be populated.
		//

		return is_a($existing_record, $target)
			? $existing_record
			: new $target();
	}
}

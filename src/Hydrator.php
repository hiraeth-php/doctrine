<?php

namespace Hiraeth\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\Common\Collections;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Persistence\Proxy;
use RuntimeException;

/**
 *
 */
class Hydrator
{
	use PropertyAccess;

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

		if ($entity instanceof Proxy && !$entity->__isInitialized()) {
			$entity->__load();
		}

		foreach ($data as $field => $value) {
			if ($protect && array_intersect(['*', $field], $entity::$_protect ?? ['*'])) {
				continue;
			}

			if (array_key_exists($field, $meta_data->embeddedClasses)) {
				$embeddable = $this->getProperty($entity, $field);

				if (!$embeddable) {
					$embeddable = new $meta_data->embeddedClasses[$field]['class']();
					$this->setProperty($entity, $field, $embeddable);
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

				$this->setProperty($entity, $field, $value);

			} elseif (array_key_exists($field, $meta_data->associationMappings)) {
				$this->fillAssociation($entity, $field, $value, $protect);

			} else {
				$this->setProperty($entity, $field, $value);

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

		if ($mapping['isOwningSide']) {
			$link = $mapping['inversedBy'];
		} else {
			$link = $mapping['mappedBy'];
		}

		switch ($mapping['type']) {
			case ClassMetadataInfo::ONE_TO_ONE:
			case ClassMetadataInfo::MANY_TO_ONE:
				$this->fillAssociationToOne($entity, $field, $link, $value, $protect);
				break;

			case ClassMetadataInfo::MANY_TO_MANY:
			case ClassMetadataInfo::ONE_TO_MANY:
				$this->fillAssociationToMany($entity, $field, $link, $value, $protect);
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
	protected function fillAssociationToMany(object $entity, string $field, ?string $link, $values, bool $protect = TRUE): self
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
	 *
	 */
	protected function fillAssociationToOne(object $entity, string $field, ?string $link, $value, bool $protect = TRUE): self
	{
		$current_value  = $this->getProperty($entity, $field, TRUE);
		$related_entity = $this->findAssociated($entity, $field, $value);

		if (is_array($value)) {
			$this->fill($related_entity, $value, $protect);
		}

		$this->setProperty($entity, $field, $related_entity, TRUE);

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

		return $this;
	}


	/**
	 *
	 */
	protected function findAssociated($entity, $field, $id, $lock_mode = NULL, $lock_version = NULL): ?object
	{
		$class     = get_class($entity);
		$manager   = $this->registry->getManagerForClass($class);
		$meta_data = $manager->getClassMetaData($class);
		$mapping   = $meta_data->getAssociationMapping($field);
		$target    = $mapping['targetEntity'] ?? NULL;

		if (!$class) {
			throw new RuntimeException(sprintf(
				'Could not determine target entity for field "%s"',
				$field
			));
		}

		//
		// Short circuit logic.  If we're not an array and we're an empty vaulue or existing
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

		if (count($id) == count($target_id_fields)) {
			$existing_record = $manager->find($target, $id, $lock_mode, $lock_version);
		} else {
			$existing_record = $this->getProperty($entity, $field, TRUE);
		}

		//
		// Lastly, if we have an existing record, we'll return that, if not, we'll return a totally
		// new one so that it can be populated.
		//

		return is_a($existing_record, $target)
			? $existing_record
			: new $target();
	}
}

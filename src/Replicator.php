<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;

/**
 * @template T of AbstractEntity
 */
class Replicator
{
	use PropertyAccess;

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
	 * @param ?T $entity
	 * @param array<string, mixed> $data
	 * @param array<string|int, string> $source
	 * @return ?T
	 */
	public function clone(?AbstractEntity $entity, array $data = array(), array $source = array()): ?AbstractEntity
	{
		if (is_null($entity)) {
			return NULL;
		}

		$original = $entity;
		$entity   = clone $original;
		$class    = $this->registry->getClassName($entity);
		$manager  = $this->registry->getManagerForClass($class);
		$mappings = $manager->getClassMetaData($class)->getAssociationMappings();

		foreach ($data as $field => $value) {
			$this->setProperty($entity, $field, $value);
		}

		foreach ($mappings as $field => $mapping) {
			if (isset($data[$field])) {
				continue;
			}

			if ($mapping->isOneToOne()) {
				$this->setProperty(
					$entity,
					$field,
					$this->clone(
						$this->getProperty($entity, $field, TRUE),
						$mapping instanceof OwningSideMapping
							? [$mapping->inversedBy => $entity]
							: (
								$mapping instanceof InverseSideMapping
									? [$mapping->mappedBy => $entity]
									: []
							),
						[
							$class => $mapping['fieldName']
						]
					)
				);
			}

			if ($mapping->isOneToMany()) {
				$collection = clone $this->getProperty($entity, $field, TRUE);

				$this->setProperty(
					$entity,
					$field,
					$collection->map(
						function($related_entity) use ($mapping, $entity, $class, $field) {
							return $this->clone(
								$related_entity,
								[
									$mapping->mappedBy => $entity
								],
								[
									$class => $field
								]
							);
						}
					)
				);
			}
		}

		//
		// Now we look through essentially all meta data to find possible relations to this
		// entity for which the entity is not aware.
		//

		foreach ($manager->getMetadataFactory()->getAllMetaData() as $meta_data) {
			if (!$meta_data instanceof ClassMetadata) {
				continue;
			}

			foreach ($meta_data->getAssociationMappings() as $field => $mapping) {
				if (!is_a($class, $mapping->targetEntity, TRUE)) {
					//
					// If our class is not a version of thet target entity, we aren't concerned
					// with the mapping.
					//

					continue;
				}

				if ($mapping instanceof InverseSideMapping && $mapping->mappedBy) {
					//
					// If this mapping is on the inverse side and is mapped by something else
					// it or don't want it because we already have it.
					//

					continue;
				}

				if ($mapping instanceof OwningSideMapping && $mapping->inversedBy) {
					//
					// If this mapping is on the owning side and is inversed by something else
					// it or don't want it because we already have it.
					//

					continue;
				}

				if (array_search($field, $source) == $mapping->sourceEntity) {
					//
					// If this mapping is a re-discovery of our source, then we already have
					// it.
					//

					continue;
				}

				if ($mapping->isOneToOne()) {
					/**
					 * @var ?T
					 */
					$related_entity = $manager->getRepository($meta_data->name)->findOneBy([
						$field => $original
					]);

					if ($related_entity) {
						$this->clone(
							$related_entity,
							[$field => $entity]
						);
					}
				}

				if ($mapping->isManyToOne()) {
					/**
					 * @var T[]
					 */
					$collection = $manager->getRepository($meta_data->name)->findBy([
						$field => $original
					]);

					foreach ($collection as $related_entity) {
						$this->clone(
							$related_entity,
							[$field => $entity]
						);
					}
				}
			}
		}

		$manager->persist($entity);

		return $entity;
	}
}

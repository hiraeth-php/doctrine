<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 *
 */
class Replicator
{
	/**
	 *
	 */
	public function __construct(ManagerRegistry $registry, Hydrator $hydrator)
	{
		$this->registry = $registry;
		$this->hydrator = $hydrator;
	}


	/**
	 *
	 */
	public function clone($entity, array $data = array(), array $source = array())
	{
		$original = $entity;
		$entity   = clone $original;
		$class    = get_class($entity);
		$manager  = $this->registry->getManagerForClass($class);

		foreach ($data as $field => $value) {
			$this->hydrator->setProperty($entity, $field, $value);
		}

		foreach ($manager->getClassMetaData($class)->associationMappings as $mapping) {
			if (isset($data[$mapping['fieldName']])) {
				continue;
			}

			if ($mapping['type'] == ClassMetadataInfo::ONE_TO_ONE) {
				$this->hydrator->setProperty(
					$entity,
					$mapping['fieldName'],
					$this->clone(
						$this->hydrator->getProperty($entity, $mapping['fieldName']),
						$mapping['inversedBy']
							? [$mapping['inversedBy'] => $entity]
							: (
								$mapping['mappedBy']
									? [$mapping['mappedBy'] => $entity]
									: []
							),
						[
							$class => $mapping['fieldName']
						]
					)
				);
			}

			if ($mapping['type'] == ClassMetadataInfo::ONE_TO_MANY) {
				$collection = clone $this->hydrator->getProperty($entity, $mapping['fieldName']);

				$this->hydrator->setProperty(
					$entity,
					$mapping['fieldName'],
					$collection->map(
						function($related_entity) use ($mapping, $entity, $class) {
							return $this->clone(
								$related_entity,
								$mapping['mappedBy']
									? [$mapping['mappedBy'] => $entity]
									: [],
								[
									$class => $mapping['fieldName']
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
			foreach ($meta_data->associationMappings as $mapping) {
				if (!is_a($class, $mapping['targetEntity'], TRUE)) {
					//
					// If our class is not a version of thet target entity, we aren't concerned
					// with the mapping.
					//

					continue;
				}

				if ($mapping['inversedBy'] || $mapping['mappedBy']) {
					//
					// If this mapping is inversed or mapped by something else then we already have
					// it or don't want it.
					//

					continue;
				}

				if (array_search($mapping['fieldName'], $source) == $mapping['sourceEntity']) {
					//
					// If this mapping is a re-discovery of our source, then we already have
					// it.
					//

					continue;
				}

				if ($mapping['type'] == ClassMetadataInfo::ONE_TO_ONE) {
					$related_entity = $manager->getRepository($meta_data->name)->findOneBy([
						$mapping['fieldName'] => $original
					]);

					if ($related_entity) {
						$manager->persist(
							$this->clone(
								$related_entity,
								[$mapping['fieldName'] => $entity]
							)
						);
					}

				}

				if ($mapping['type'] == ClassMetadataInfo::MANY_TO_ONE) {
					$collection = $manager->getRepository($meta_data->name)->findBy([
						$mapping['fieldName'] => $original
					]);

					foreach ($collection as $related_entity) {
						$manager->persist(
							$this->clone(
								$related_entity,
								[$mapping['fieldName'] => $entity]
							)
						);
					}
				}
			}
		}

		return $entity;
	}
}

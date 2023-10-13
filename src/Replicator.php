<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * @template Entity of object
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
	 * @param Entity|null $entity
	 * @param array<string, mixed> $data
	 * @param array<string|int, string> $source
	 * @return Entity|null
	 */
	public function clone(?object $entity, array $data = array(), array $source = array()): ?object
	{
		if (is_null($entity)) {
			return NULL;
		}

		$original = $entity;
		$entity   = clone $original;
		$class    = $this->registry->getClassName($entity);
		$manager  = $this->registry->getManagerForClass($class);

		foreach ($data as $field => $value) {
			$this->setProperty($entity, $field, $value);
		}

		foreach ($manager->getClassMetaData($class)->associationMappings as $mapping) {
			if (isset($data[$mapping['fieldName']])) {
				continue;
			}

			if ($mapping['type'] == ClassMetadataInfo::ONE_TO_ONE) {
				$this->setProperty(
					$entity,
					$mapping['fieldName'],
					$this->clone(
						$this->getProperty($entity, $mapping['fieldName'], TRUE),
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
				$collection = clone $this->getProperty($entity, $mapping['fieldName'], TRUE);

				$this->setProperty(
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
						$this->clone(
							$related_entity,
							[$mapping['fieldName'] => $entity]
						);
					}
				}

				if ($mapping['type'] == ClassMetadataInfo::MANY_TO_ONE) {
					$collection = $manager->getRepository($meta_data->name)->findBy([
						$mapping['fieldName'] => $original
					]);

					foreach ($collection as $related_entity) {
						$this->clone(
							$related_entity,
							[$mapping['fieldName'] => $entity]
						);
					}
				}
			}
		}

		$manager->persist($entity);

		return $entity;
	}
}

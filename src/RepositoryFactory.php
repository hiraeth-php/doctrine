<?php

namespace Hiraeth\Doctrine;

use Psr\Container\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Repository;


/**
 *
 */
class RepositoryFactory implements Repository\RepositoryFactory
{
	/**
	 * @var ContainerInterface
	 */
	protected $container;


	/**
	 *
	 */
	public function __construct(ContainerInterface $container)
	{
		$this->container = $container;
	}


	/**
	 * @param class-string<Entity> $name The name of the entity.
	 *
	 * @template Entity of object
	 */
	public function getRepository(EntityManagerInterface $manager, string $name): EntityRepository
	{
		$meta_data = $manager->getClassMetaData($name);

		return $this->container->get($meta_data->customRepositoryClassName);
	}
}

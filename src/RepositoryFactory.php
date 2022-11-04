<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Repository;
use Psr\Container\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;


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
	 *
	 */
	public function getRepository(EntityManagerInterface $manager, $name)
	{
		$meta_data = $manager->getClassMetaData($name);

		return $this->container->get($meta_data->customRepositoryClassName);
	}
}

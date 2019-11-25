<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Doctrine\ORM\EntityManager;

/**
 * {@inheritDoc}
 */
class EntityManagerDelegate implements Hiraeth\Delegate
{
	/**
	 * {@inheritDoc}
	 */
	public static function getClass(): string
	{
		return EntityManager::class;
	}


	/**
	 * {@inheritDoc}
	 */
	public function __invoke(Hiraeth\Application $app): object
	{
		return $app->get(ManagerRegistry::class)->getManager();
	}
}

<?php

namespace Hiraeth\Doctrine;

use Hiraeth;

/**
 * {@inheritDoc}
 */
class HydratorDelegate implements Hiraeth\Delegate
{
	/**
	 * {@inheritDoc}
	 */
	public static function getClass(): string
	{
		return Hydrator::class;
	}


	/**
	 * {@inheritDoc}
	 */
	public function __invoke(Hiraeth\Application $app): object
	{
		$instance = new Hydrator($app->get(ManagerRegistry::class));
		$filters  = $app->getConfig('*', 'doctrine.hydrator.filters', []);

		foreach (array_merge(...array_values($filters)) as $type => $filter) {
			$instance->addFilter($type, $app->get($filter));
		}

		return $app->share($instance);
	}
}

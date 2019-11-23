<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Doctrine\Common\Proxy\Autoloader;

/**
 *
 */
class ApplicationProvider implements Hiraeth\Provider
{
	/**
	 * {@inheritDoc}
	 */
	static public function getInterfaces(): array
	{
		return [
			Hiraeth\Application::class
		];
	}


	/**
	 * {@inheritDoc}
	 */
	public function __invoke($instance, Hiraeth\Application $app): object
	{
		$defaults = [
			'namespace' => NULL,
			'directory' => NULL
		];

		foreach ($app->getConfig('*', 'manager.proxy', $defaults) as $path => $proxy) {
			$name      = basename($path, '.jin');
			$proxy_ns  = $proxy['namespace'] ?? 'Proxies' . md5($name);
			$proxy_dir = $proxy['directory'] ?? $this->app->getDirectory(
				'storage/proxies/' . $name
			);

			Autoloader::register($proxy_dir, $proxy_ns);
		}

		return $instance;
	}
}

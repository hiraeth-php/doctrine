<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Doctrine\ORM;
use Doctrine\DBAL;
use Doctrine\ORM\Query;
use Doctrine\Common\Persistence;
use Doctrine\Common\Cache;

use ReflectionClass;
use RuntimeException;
use InvalidArgumentException;
use Hiraeth\Dbal\ConnectionRegistry;
use Hiraeth\Caching\PoolManagerInterface;
use Cache\Bridge\Doctrine\DoctrineCacheBridge;

/**
 *
 */
class ManagerRegistry implements Persistence\ManagerRegistry
{
	/**
	 *
	 */
	protected $app = NULL;


	/**
	 *
	 */
	protected $connectionRegistry = NULL;


	/**
	 *
	 */
	protected $defaultManager = NULL;


	/**
	 *
	 */
	protected $managers = array();


	/**
	 *
	 */
	protected $managerCollections = array();


	/**
	 *
	 */
	protected $paths = array();


	/**
	 *
	 */
	protected $pools = NULL;


	/**
	 *
	 */
	public function __construct(Hiraeth\Application $app, ConnectionRegistry $connection_registry)
	{
		$this->app                = $app;
		$this->defaultManager     = 'default';
		$this->connectionRegistry = $connection_registry;

		if ($app->has(PoolManagerInterface::class)) {
			$this->pools = $app->get(PoolManagerInterface::class);
		}

		foreach ($app->getConfig('*', 'manager', []) as $path => $config) {
			if (isset($config['connection'])) {
				$name = basename($path);

				if (isset($this->managerCollections[$name])) {
					throw new RuntimeException(sprintf(
						'Cannot add manager "%s", name already used',
						$name
					));
				}

				$this->managerCollections[$name] = $path;
			}
		}

		$app->share($this);
	}


	/**
	 *
	 */
	public function addEntityPath($name, $path): ManagerRegistry
	{
		if (!isset($this->paths[$name])) {
			$this->paths[$name] = array();
		}

		$this->paths[$name][] = $path;

		return $this;
	}


	/**
	 *
	 */
	public function getAliasNamespace($alias)
	{
		foreach (array_keys($this->getManagers()) as $name) {
			$alias = $this->getManager($name)->getConfiguration()->getEntityNamespace($alias);

			if ($alias) {
				return $alias;
			}
		}
	}


	/**
	 * {@inheritdoc}
	 */
	public function getConnection($name = null): DBAL\Connection
	{
		return $this->connectionRegistry->getConnection($name);
	}


	/**
	 * {@inheritdoc}
	 */
	public function getConnectionNames(): array
	{
		return $this->connectionRegistry->getConnectionNames();
	}


	/**
	 * {@inheritdoc}
	 */
	public function getConnections(): array
	{
		return $this->connectionRegistry->getConnections();
	}


	/**
	 * {@inheritdoc}
	 */
	public function getDefaultConnectionName(): string
	{
		return $this->connectionRegistry->getDefaultConnectionName();
	}


	/**
	 * {@inheritdoc}
	 */
	public function getDefaultManagerName()
	{
		return $this->defaultManager;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManager($name = null): Persistence\ObjectManager
	{
		if ($name === null) {
			$name = $this->defaultManager;
		}

		if (!isset($this->managerCollections[$name])) {
			throw new InvalidArgumentException(sprintf('Doctrine manager named "%s" does not exist.', $name));
		}

		if (!isset($this->managers[$name])) {
			$paths      = array();
			$config     = new ORM\Configuration();
			$collection = $this->managerCollections[$name];
			$options    = $this->app->getConfig($collection, 'manager', []) + [
				'cache'      => NULL,
				'connection' => 'default',
				'walkers'    => [],
				'paths'      => []
			];

			if ($this->app->isDebugging()) {
				$cache = $this->app->get(Cache\ArrayCache::class);

				$config->setAutoGenerateProxyClasses(TRUE);
				$config->setAutoGenerateProxyClasses(ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
			} else {
				if ($options['cache']) {
					$pool = $this->pools->get($options['cache']);
				} else {
					$pool = $this->pools->getDefaultPool();
				}

				$cache = new DoctrineCacheBridge($pool);
			}

			foreach ($options['paths'] as $path) {
				$paths[] = $this->app->getDirectory($path)->getPathname();
			}

			if (isset($this->paths[$name])) {
				$paths = array_merge($paths, $this->paths[$name]);
			}

			$proxy_ns  = $options['proxy']['namespace'] ?? 'Proxies\\Default';
			$proxy_dir = $options['proxy']['directory'] ?? 'storage/proxies/default';
			$driver    = $config->newDefaultAnnotationDriver($paths);

			if ($options['walkers']) {
				$config->setDefaultQueryHint(
					Query::HINT_CUSTOM_TREE_WALKERS,
					$options['walkers']
				);
			}

			$config->setProxyDir($this->app->getDirectory($proxy_dir, TRUE)->getRealPath());
			$config->setProxyNamespace($proxy_ns);
			$config->setMetadataDriverImpl($driver);
			$config->setMetadataCacheImpl($cache);
			$config->setQueryCacheImpl($cache);

			$this->managers[$name] = ORM\EntityManager::create(
				$this->getConnection($options['connection']),
				$config
			);
		}

		return $this->managers[$name];
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManagerForClass($class)
	{
		if (strpos($class, ':') !== false) {
			$parts = explode(':', $class, 2);
			$alias = $parts[0];
			$class = $this->getAliasNamespace($alias) . '\\' . $parts[1];
		}

		$reflection = new ReflectionClass($class);

		if ($reflection->implementsInterface(Persistence\Proxy::class)) {
			$parent = $reflection->getParentClass();

			if (!$parent) {
				return null;
			}

			$class = $parent->getName();
		}

		foreach ($this->getManagers() as $name => $manager) {
			if (!$manager->getMetadataFactory()->isTransient($class)) {
				return $manager;
			}
		}
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManagerNames()
	{
		return array_keys($this->managerCollections);
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManagers()
	{
		foreach ($this->managerCollections as $name => $collection) {
			if (!isset($this->managers[$name])) {
				$this->managers[$name] = $this->getManager($name);
			}
		}

		return $this->managers;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getRepository($object_name, $manager_name = null)
	{
		if ($manager_name) {
			$manager = $this->getManager($manager_name);
		} else {
			$manager = $this->getManagerForClass($object_name) ?? $this->getManager();
		}

		return $manager->getRepository($object_name);
	}


	/**
	 *
	 */
	public function resetManager($name = null)
	{
		if (isset($this->managers[$name])) {
			unset($this->managers[$name]);
		}
	}
}

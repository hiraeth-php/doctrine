<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Doctrine\ORM;
use Doctrine\DBAL;
use Doctrine\Persistence;
use Doctrine\Common\Proxy\AbstractProxyFactory;

use ReflectionClass;
use RuntimeException;
use InvalidArgumentException;
use Hiraeth\Caching\PoolManager;
use Hiraeth\Dbal\ConnectionRegistry;

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

		if ($app->has(PoolManager::class)) {
			$this->pools = $app->get(PoolManager::class);
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

			$subscribers = $this->app->getConfig('*', 'subscriber', [
				'class'    => NULL,
				'disabled' => FALSE,
				'priority' => 50,
				'manager'  => []
			]);

			$options = $this->app->getConfig($collection, 'manager', []) + [
				'cache'      => NULL,
				'connection' => 'default',
				'paths'      => []
			];

			$config->setRepositoryFactory($this->app->get(RepositoryFactory::class));

			if ($this->app->getEnvironment('CACHING', FALSE)) {
				if ($options['cache']) {
					$pool = $this->pools->get($options['cache']);
				} else {
					$pool = $this->pools->getDefaultPool();
				}

				$config->setMetadataCache($pool);
				$config->setQueryCache($pool);

			} else {
				$config->setAutoGenerateProxyClasses(TRUE);
				$config->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_ALWAYS);

			}

			foreach ($options['paths'] as $path) {
				$paths[] = $this->app->getDirectory($path)->getRealPath();
			}

			if (isset($this->paths[$name])) {
				$paths = array_merge($paths, $this->paths[$name]);
			}

			$driver    = $config->newDefaultAnnotationDriver($paths);
			$proxy_ns  = $options['proxy']['namespace'] ?? ucwords($name) . 'Proxies';
			$proxy_dir = $options['proxy']['directory'] ?? $this->app->getDirectory(
				'storage/proxies/' . $name
			);

			if (!empty($options['walkers']['output'])) {
				$config->setDefaultQueryHint(ORM\Query::HINT_CUSTOM_OUTPUT_WALKER, $options['walkers']['output']);
			}

			if (!empty($options['walkers']['tree'])) {
				$config->setDefaultQueryHint(ORM\Query::HINT_CUSTOM_TREE_WALKERS, $options['walkers']['tree']);
			}

			$config->setProxyDir($proxy_dir);
			$config->setProxyNamespace($proxy_ns);
			$config->setMetadataDriverImpl($driver);

			$this->managers[$name] = ORM\EntityManager::create(
				$this->getConnection($options['connection']),
				$config
			);

			//
			// Event Subscribers are added after to prevent cyclical dependencies in the event
			// the subscriber has a repository or entity manager re-injected
			//

			uasort($subscribers, function($a, $b) {
				return $a['priority'] - $b['priority'];
			});

			foreach ($subscribers as $collection => $config) {
				settype($config['manager'], 'array');

				if (!empty($config['disabled'])) {
					continue;
				}

				if (!in_array($name, $config['manager'])) {
					continue;
				}

				$this->managers[$name]->getEventManager()->addEventSubscriber(
					$this->app->get($config['class'])
				);
			}
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

<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Hiraeth\Caching\PoolManager;
use Hiraeth\Dbal\ConnectionRegistry;

use Doctrine\ORM;
use Doctrine\DBAL;
use Doctrine\Persistence;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;

use InvalidArgumentException;
use RuntimeException;
use ReflectionClass;

/**
 * The manager registry
 */
class ManagerRegistry implements Persistence\ManagerRegistry, EntityManagerProvider
{
	/**
	 * @var Hiraeth\Application
	 */
	protected $app;


	/**
	 * @var ConnectionRegistry
	 */
	protected $connectionRegistry;

	/**
	 * @var string
	 */
	protected $defaultManager = 'default';


	/**
	 * @var array<string, EntityManager>
	 */
	protected $managers = array();


	/**
	 * @var array<string, string>
	 */
	protected $managerConfigs = array();


	/**
	 * @var array<string, string[]>
	 */
	protected $paths = array();


	/**
	 * @var PoolManager
	 */
	protected $pools;


	/**
	 * Construct a new registry
	 */
	public function __construct(Hiraeth\Application $app, ConnectionRegistry $connection_registry)
	{
		$this->app                = $app;
		$this->connectionRegistry = $connection_registry;

		if ($app->has(PoolManager::class)) {
			$this->pools = $app->get(PoolManager::class);
		}

		foreach ($app->getConfig('*', 'manager', []) as $path => $config) {
			if (isset($config['connection'])) {
				$name = basename($path);

				if (isset($this->managerConfigs[$name])) {
					throw new RuntimeException(sprintf(
						'Cannot add manager "%s", name already used',
						$name
					));
				}

				$this->managerConfigs[$name] = $path;
			}
		}

		$app->share($this);
	}


	/**
	 * @param string $manager_name
	 * @param string $path
	 * @return static
	 */
	public function addEntityPath(string $manager_name, string $path): self
	{
		if (!isset($this->paths[$manager_name])) {
			$this->paths[$manager_name] = array();
		}

		$this->paths[$manager_name][] = $path;

		return $this;
	}


	/**
	 * @param object|class-string $entity
	 * @return class-string
	 */
	public function getClassName($entity)
	{
		if (is_object($entity)) {
			$class = get_class($entity);
		} else {
			$class = $entity;
		}

		if (!class_exists($class)) {
			throw new InvalidArgumentException(sprintf(
				'Invalid value %s cannot be converted to a valid class',
				$class
			));
		}

		return $class;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getConnection(string $name = null): DBAL\Connection
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
	 * {@inheritDoc}
	 */
	public function getDefaultManager(): EntityManager
	{
		return $this->getManager();
	}


	/**
	 * {@inheritdoc}
	 */
	public function getDefaultManagerName(): string
	{
		return $this->defaultManager;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManager(string $name = null): EntityManager
	{
		if ($name === null) {
			$name = $this->defaultManager;
		}

		if (!isset($this->managerConfigs[$name])) {
			throw new InvalidArgumentException(sprintf('Doctrine manager named "%s" does not exist.', $name));
		}

		if (!isset($this->managers[$name])) {
			$pool          = NULL;
			$paths         = array();
			$collection    = $this->managerConfigs[$name];
			$configuration = new ORM\Configuration();

			$subscribers = $this->app->getConfig('*', 'subscriber', [
				'class'    => NULL,
				'disabled' => FALSE,
				'priority' => 50,
				'manager'  => []
			]);

			$config = $this->app->getConfig($collection, 'manager', []) + [
				'driver'     => AttributeDriver::class,
				'options'    => array(),
				'unmanaged'  => array(),
				'connection' => 'default',
				'cache'      => NULL,
			];

			$configuration->setRepositoryFactory($this->app->get(RepositoryFactory::class));

			if ($this->app->getEnvironment('CACHING', FALSE)) {
				if ($config['cache']) {
					$pool = $this->pools->get($config['cache']);
				} else {
					$pool = $this->pools->getDefaultPool();
				}

				$configuration->setMetadataCache($pool);
				$configuration->setQueryCache($pool);
			} else {
				$configuration->setAutoGenerateProxyClasses(TRUE);
			}

			if (isset($this->paths[$name])) {
				$paths = array_merge($paths, $this->paths[$name]);
			}

			if (!class_exists($config['driver'])) {
				throw new RuntimeException(sprintf(
					'Invalid driver class specified "%s", class does not exist',
					$config['driver']
				));
			}

			$connection = $this->getConnection($config['connection']);
			$driver     = $this->app->get($config['driver'], $config['options']);
			$proxy_ns   = $config['proxy']['namespace'] ?? ucwords($name) . 'Proxies';
			$proxy_dir  = $config['proxy']['directory'] ?? $this->app->getDirectory(
				'storage/proxies/' . $name
			);


			if (!empty($config['unmanaged'])) {
				$connection->getConfiguration()->setSchemaAssetsFilter(
					function($object) use ($config) {
						return !in_array($object, $config['unmanaged']);
					}
				);
			}

			foreach ($config['functions'] ?? [] as $type => $classes) {
				foreach ($classes as $function => $class) {
					$method = sprintf('addCustom%sFunction', $type);

					$configuration->$method($function, $class);
				}
			}

			if (!empty($config['walkers']['output'])) {
				$configuration->setDefaultQueryHint(
					ORM\Query::HINT_CUSTOM_OUTPUT_WALKER,
					$config['walkers']['output']
				);
			}

			if (!empty($config['walkers']['tree'])) {
				$configuration->setDefaultQueryHint(
					ORM\Query::HINT_CUSTOM_TREE_WALKERS,
					$config['walkers']['tree']
				);
			}

			$configuration->setProxyDir($proxy_dir);
			$configuration->setProxyNamespace($proxy_ns);
			$configuration->setMetadataDriverImpl($driver);

			$this->managers[$name] = new EntityManager($connection, $configuration);

			//
			// Event Subscribers are added after to prevent cyclical dependencies in the event
			// the subscriber has a repository or entity manager re-injected
			//

			uasort($subscribers, function($a, $b) {
				return $a['priority'] - $b['priority'];
			});

			foreach ($subscribers as $collection => $configuration) {
				settype($configuration['manager'], 'array');

				if (!empty($configuration['disabled'])) {
					continue;
				}

				if (!in_array($name, $configuration['manager'])) {
					continue;
				}

				$this->managers[$name]->getEventManager()->addEventSubscriber(
					$this->app->get($configuration['class'])
				);
			}
		}

		return $this->managers[$name];
	}


	/**
	 * @param class-string<T> $class
	 * @template T of object
	 */
	public function getManagerForClass(string $class): ?EntityManager
	{
		$class      = $this->getClassName($class);
		$reflection = new ReflectionClass($class);

		if ($reflection->implementsInterface(Persistence\Proxy::class)) {
			$parent = $reflection->getParentClass();

			if (!$parent) {
				return NULL;
			}

			$class = $parent->getName();
		}

		foreach ($this->getManagers() as $manager) {
			if (!$manager->getMetadataFactory()->isTransient($class)) {
				return $manager;
			}
		}

		return NULL;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManagerNames(): array
	{
		return array_combine(
			array_keys($this->managerConfigs),
			array_map(
				function ($collection): string {
					return $this->app->getConfig($collection, 'manager.name', 'Unknown Name');
				},
				$this->managerConfigs
			)
		) ?: array();
	}


	/**
	 * @return array<string, EntityManager>
	 */
	public function getManagers(): array
	{
		foreach ($this->managerConfigs as $name => $collection) {
			if (!isset($this->managers[$name])) {
				$this->managers[$name] = $this->getManager($name);
			}
		}

		return $this->managers;
	}


	/**
	 * {@inheritDoc}
	 *
	 * @param class-string<T> $class
	 * @return EntityRepository<T>
	 * @template T of object
	 */
	public function getRepository(string $class, string $manager_name = null): EntityRepository
	{
		if ($manager_name) {
			$manager = $this->getManager($manager_name);
		} else {
			$manager = $this->getManagerForClass($class) ?? $this->getManager();
		}

		return $manager->getRepository($class);
	}


	/**
	 * Reset a manager by re-reading its configs and establishing new dependencies
	 */
	public function resetManager(string $name = null): EntityManager
	{
		if (!$name) {
			$name = $this->defaultManager;
		}

		if (isset($this->managers[$name])) {
			unset($this->managers[$name]);
		}

		return $this->getManager($name);
	}
}

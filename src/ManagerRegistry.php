<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Hiraeth\Caching\PoolManager;
use Hiraeth\Dbal\ConnectionRegistry;

use Doctrine\ORM;
use Doctrine\DBAL;
use Doctrine\Persistence;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\SimpleAnnotationReader;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;

use ReflectionClass;
use RuntimeException;
use InvalidArgumentException;

/**
 * The manager registry
 */
class ManagerRegistry implements Persistence\ManagerRegistry
{
	/**
	 * A list of older readers for driver implementation
	 *
	 * @var array
	 */
	static protected $annotationDrivers = [
		SimpleAnnotationReader::class,
		AnnotationReader::class
	];

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
	 * @var array<string, Persistence\ObjectManager>
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
	public function addEntityPath(string $manager_name, string $path): ManagerRegistry
	{
		if (!isset($this->paths[$manager_name])) {
			$this->paths[$manager_name] = array();
		}

		$this->paths[$manager_name][] = $path;

		return $this;
	}


	/**
	 * @param string $alias
	 * @return string|null
	 */
	public function getAliasNamespace(string $alias)
	{
		foreach (array_keys($this->getManagers()) as $name) {
			$manager = $this->getManager($name);

			if ($manager instanceof ORM\EntityManager) {
				$namespace = $manager->getConfiguration()->getEntityNamespace($alias);

				if ($namespace) {
					return $namespace;
				}
			}
		}

		return NULL;
	}


	/**
	 *
	 *
	 * @param object|string $entity
	 * @return class-string
	 */
	public function getClassName($entity)
	{
		if (is_object($entity)) {
			if ($entity instanceof Proxy) {
				$class = get_parent_class($entity);
			} else {
				$class = get_class($entity);
			}

		} elseif (strpos($entity, ':') !== FALSE) {
			$parts = explode(':', $entity, 2);
			$alias = $parts[0];
			$class = $this->getAliasNamespace($alias) . '\\' . $parts[1];

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
	 * {@inheritdoc}
	 */
	public function getDefaultManagerName(): string
	{
		return $this->defaultManager;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getManager(string $name = null): Persistence\ObjectManager
	{
		if ($name === null) {
			$name = $this->defaultManager;
		}

		if (!isset($this->managerConfigs[$name])) {
			throw new InvalidArgumentException(sprintf('Doctrine manager named "%s" does not exist.', $name));
		}

		if (!isset($this->managers[$name])) {
			$pool       = NULL;
			$paths      = array();
			$config     = new ORM\Configuration();
			$collection = $this->managerConfigs[$name];

			$subscribers = $this->app->getConfig('*', 'subscriber', [
				'class'    => NULL,
				'disabled' => FALSE,
				'priority' => 50,
				'manager'  => []
			]);

			$options = $this->app->getConfig($collection, 'manager', []) + [
				'cache'      => NULL,
				'driver'     => SimpleAnnotationReader::class,
				'connection' => 'default',
				'unmanaged'  => [],
				'paths'      => [],
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

			$connection = $this->getConnection($options['connection']);
			$proxy_ns   = $options['proxy']['namespace'] ?? ucwords($name) . 'Proxies';
			$proxy_dir  = $options['proxy']['directory'] ?? $this->app->getDirectory(
				'storage/proxies/' . $name
			);

			if (in_array($options['driver'], static::$annotationDrivers)) {
				$reader = $this->app->get($options['driver']);
				$driver = new AnnotationDriver($reader, $paths);

				if ($reader instanceof SimpleAnnotationReader) {
					$reader->addNamespace('Doctrine\ORM\Mapping');
				}

			} else {
				$driver = $this->app->get($options['driver'], [$paths]);

			}

			if (!empty($options['unmanaged'])) {
				$connection->getConfiguration()->setSchemaAssetsFilter(
					function($object) use ($options) {
						return !in_array($object, $options['unmanaged']);
					}
				);
			}

			foreach ($options['functions'] ?? [] as $type => $classes) {
				foreach ($classes as $function => $class) {
					$method = sprintf('addCustom%sFunction', $type);

					$config->$method($function, $class);
				}
			}

			if (!empty($options['walkers']['output'])) {
				$config->setDefaultQueryHint(ORM\Query::HINT_CUSTOM_OUTPUT_WALKER, $options['walkers']['output']);
			}

			if (!empty($options['walkers']['tree'])) {
				$config->setDefaultQueryHint(ORM\Query::HINT_CUSTOM_TREE_WALKERS, $options['walkers']['tree']);
			}

			$config->setProxyDir($proxy_dir);
			$config->setProxyNamespace($proxy_ns);
			$config->setMetadataDriverImpl($driver);

			$this->managers[$name] = new ORM\EntityManager($connection, $config);

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
	 *
	 * @param class-string $class
	 */
	public function getManagerForClass(string $class): ?Persistence\ObjectManager
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
	 * {@inheritdoc}
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
	 * Gets the ObjectRepository for a persistent object.
	 */
	public function getRepository(string $class, string $manager_name = null): ?AbstractRepository
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
	public function resetManager(string $name = null): Persistence\ObjectManager
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

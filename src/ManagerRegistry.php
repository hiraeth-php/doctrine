<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Hiraeth\Caching\PoolManager;
use Hiraeth\Dbal\ConnectionRegistry;

use Doctrine\ORM;
use Doctrine\DBAL;
use Doctrine\Persistence;
use Doctrine\ORM\Configuration;
use Doctrine\Common\Proxy\AbstractProxyFactory;

use ReflectionClass;
use RuntimeException;
use InvalidArgumentException;

/**
 *
 */
class ManagerRegistry implements Persistence\ManagerRegistry
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
	 * @var array<string, ORM\EntityManager>
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
	 *
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
	 * @param string $name
	 * @param string $path
	 * @return static
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
	 * @param string $alias
	 * @return string|null
	 */
	public function getAliasNamespace($alias)
	{
		foreach (array_keys($this->getManagers()) as $name) {
			$namespace = $this->getManager($name)->getConfiguration()->getEntityNamespace($alias);

			if ($namespace) {
				return $namespace;
			}
		}

		return NULL;
	}


	/**
	 * @param object|string $entity
	 * @return class-string
	 */
	public function getClassName($entity)
	{
		if (is_object($entity)) {
			$class = get_class($entity);
		} elseif (strpos($entity, ':') !== false) {
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
	 *
	 * @return ORM\EntityManager
	 */
	public function getManager($name = null)
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
				'connection' => 'default',
				'paths'      => [],
				'unmanaged'  => [],
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
			$driver     = $config->newDefaultAnnotationDriver($paths);
			$proxy_ns   = $options['proxy']['namespace'] ?? ucwords($name) . 'Proxies';
			$proxy_dir  = $options['proxy']['directory'] ?? $this->app->getDirectory(
				'storage/proxies/' . $name
			);

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

			$this->managers[$name] = ORM\EntityManager::create($connection, $config);

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
	 * @return ORM\EntityManager|null
	 */
	public function getManagerForClass($class)
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
	public function getManagerNames()
	{
		return array_combine(
			array_keys($this->managerConfigs),
			array_map(
				function ($collection) {
					return $this->app->getConfig($collection, 'manager.name', 'Unknown  Name');
				},
				$this->managerConfigs
			)
		);
	}


	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, ORM\EntityManager>
	 */
	public function getManagers()
	{
		foreach ($this->managerConfigs as $name => $collection) {
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
		if (!$name) {
			$name = $this->defaultManager;
		}

		if (isset($this->managers[$name])) {
			unset($this->managers[$name]);
		}

		return $this->getManager($name);
	}
}

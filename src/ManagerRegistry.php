<?php

namespace Hiraeth\Doctrine;

use Hiraeth;
use Doctrine\ORM;
use Doctrine\Common\Persistence;
use Doctrine\Common\Cache;

use ReflectionClass;
use RuntimeException;
use Hiraeth\Dbal\ConnectionRegistry;
use Hiraeth\Caching\PoolManagerInterface;
use Doctrine\ORM\Proxy\ProxyFactory;
use Cache\Bridge\Doctrine\DoctrineCacheBridge;

/**
 *
 */
class ManagerRegistry extends ConnectionRegistry implements Persistence\ManagerRegistry
{
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
	protected $pools = NULL;


	/**
	 *
	 */
	public function __construct(Hiraeth\Application $app)
	{
		parent::__construct($app);

		$this->defaultManager = 'default';

		if ($app->has(PoolManagerInterface::class)) {
			$this->pools = $app->get(PoolManagerInterface::class);
		}

		foreach ($app->getConfig('*', 'doctrine.managers', []) as $config) {
			foreach ($config as $name => $collection) {
				if (isset($this->managerCollections[$name])) {
					throw new RuntimeException(sprintf(
						'Cannot add manager "%s", name already used', $name
					));
				}

				$this->managerCollections[$name] = $collection;
			}
		}
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
    public function getDefaultManagerName()
    {
        return $this->defaultManager;
    }


	/**
     * {@inheritdoc}
     */
    public function getManager($name = null)
    {
        if ($name === null) {
            $name = $this->defaultManager;
        }

        if (!isset($this->managerCollections[$name])) {
            throw new InvalidArgumentException(sprintf('Doctrine %s Manager named "%s" does not exist.', $this->name, $name));
        }

		if (!isset($this->managers[$name])) {
			$paths      = array();
			$config     = new ORM\Configuration();
			$collection = $this->managerCollections[$name];
			$options    = $this->app->getConfig($collection, 'manager', []) + [
				'connection' => 'default'
			];

			if ($this->app->isDebugging()) {
				$cache = $this->app->get(Cache\ArrayCache::class);

				$config->setAutoGenerateProxyClasses(TRUE);
				$config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);

			} else {
				if (isset($config['cache'])) {
					$pool  = $this->pools->get($config['cache']);
					$cache = new DoctrineCacheBridge($pool);
				}
			}

			foreach ($options['paths'] as $path) {
				$paths[] = $this->app->getDirectory($path)->getPathname();
			}

			$proxy_ns  = $options['proxy']['namespace'] ?? 'Proxies\\Default';
			$proxy_dir = $options['proxy']['directory'] ?? 'storage/proxies/default';
			$driver    = $config->newDefaultAnnotationDriver($paths);

			$config->setProxyDir($this->app->getDirectory($proxy_dir, TRUE)->getPathname());
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
			if (!$this->managers[$name]) {
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

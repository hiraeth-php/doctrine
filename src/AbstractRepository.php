<?php

namespace Hiraeth\Doctrine;

use InvalidArgumentException;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections;
use Doctrine\Persistence\Mapping\ClassMetadata;

/**
 * @extends EntityRepository<AbstractEntity>
 */
abstract class AbstractRepository extends EntityRepository
{
	/**
	 * @var class-string<AbstractEntity>
	 */
	protected static $entity;


	/**
	 * @var class-string<Collections\Collection>
	 */
	protected static $collection = Collection::class;


	/**
	 * @var array<string, string>
	 */
	protected static $order = [];


	/**
	 * @var Hydrator
	 */
	protected $hydrator;


	/**
	 * @var ClassMetadata<AbstractEntity>
	 */
	protected $metaData;


	/**
	 * @var EntityManager
	 */
	protected $manager;


	/**
	 * @var ManagerRegistry
	 */
	protected $registry;


	/**
	 * @var Replicator
	 */
	protected $replicator;


	/**
	 *
	 */
	public function __construct(ManagerRegistry $registry, Hydrator $hydrator, Replicator $replicator)
	{
		$this->registry   = $registry;
		$this->hydrator   = $hydrator;
		$this->replicator = $replicator;
		$this->manager    = $this->registry->getManagerForClass(static::$entity);
		$this->metaData   = $this->manager->getClassMetaData(static::$entity);

		parent::__construct($this->manager, $this->metaData);
	}


	/**
	 * @param AbstractEntity $entity
	 * @return AbstractRepository
	 */
	public function attach($entity): AbstractRepository
	{
		$this->getEntityManager()->merge($entity);

		return $this;
	}


	/**
	 * @param array<string, mixed> $data
	 * @param bool $protect
	 * @return AbstractEntity
	 */
	public function create(array $data = array(), bool $protect = TRUE): AbstractEntity
	{

		$entity = new static::$entity;

		$this->update($entity, $data, $protect);

		return $entity;
	}


	/**
	 * @param AbstractEntity $entity
	 */
	public function detach($entity): AbstractRepository
	{
		$this->getEntityManager()->detach($entity);

		return $this;
	}


	/**
	 * {@inheritDoc}

	 * @return AbstractEntity|NULL
	 */
	public function find($id, $lock_mode = NULL, $lock_version = NULL): ?AbstractEntity
	{
		if ($id === NULL) {
			return NULL;
		}

		if (is_array($id)) {
			$meta_data   = $this->getClassMetadata();
			$field_names = $meta_data->getIdentifierFieldNames();
			$id          = array_intersect_key($id, array_flip($field_names));
		}

		return parent::find($id, $lock_mode, $lock_version);
	}


	/**
	 * Standard findAll with the option to add an orderBy
	 *
	 * {@inheritDoc}
	 *
	 * @param array<string>|null $order_by The order by clause to add
	 * @return Collections\Collection<int, AbstractEntity>
	 */
	public function findAll(?array $order_by = []): Collections\Collection
	{
		return $this->findBy([], $order_by);
	}


	/**
	 * {@inheritDoc}
	 *
	 * @return Collections\Collection<int, AbstractEntity>
	 */
	public function findBy(array $criteria, ?array $order_by = [], $limit = null, $offset = null): Collections\Collection
	{
		if (!is_null($order_by)) {
			$order_by = $order_by + static::$order;
		}

		return $this->query(function ($builder) use ($criteria, $order_by, $limit, $offset) {
			$builder = $this->join($builder, array_keys($criteria));
			$param   = 1;

			foreach ($criteria as $key => $value) {
				if (strpos($key, '.') === FALSE) {
					$key = sprintf('this.%s', $key);
				}

				if ($value instanceof Collections\Collection) {
					$value = $value->getValues();
				}

				if (is_null($value)) {
					$expr = $builder->expr()->isNull($key);
				} else {
					if (is_array($value)) {
						$expr = $builder->expr()->in($key, '?' . $param);
					} else {
						$expr = $builder->expr()->eq($key, '?' . $param);
					}

					$builder->setParameter($param++, $value);
				}

				$builder->andWhere($expr);
			}

			if (!is_null($limit)) {
				$builder->setMaxResults($limit);
			}

			if (!is_null($offset)) {
				$builder->setFirstResult($offset);
			}

			return $this->order($builder, $order_by);
		});
	}


	/**
	 * {@inheritDoc}
	 * @return AbstractEntity|NULL
	 */
	public function findOneBy(array $criteria, ?array $order_by = []): ?AbstractEntity
	{
		return $this->findBy($criteria, $order_by, 1)->first() ?: NULL;
	}


	/**
	 * @param callable|string|array<mixed> $build_callback
	 * @return Collections\Collection<int, AbstractEntity>
	 */
	public function query($build_callback, ?int &$nonlimited_count = NULL, bool $cache = TRUE): Collections\Collection
	{
		$builder = $this->build($build_callback);

		if (in_array('DISTINCT this', $builder->getDQLPart('select')[0]->getParts())) {
			$builder = $this->order($builder, static::$order);
		}

		if ($nonlimited_count === 0) {
			$nonlimited_count = $this->queryCount(function() use ($builder) {
				return clone $builder;
			}, TRUE, $cache);
		}

		return $this->collect(
			$cache
				? $builder->getQuery()
				: $builder->getQuery()->useQueryCache(FALSE)
		);
	}


	/**
	 * @param callable|string|array<mixed> $build_callback
	 */
	public function queryCount($build_callback, bool $non_limited = FALSE, bool $cache = TRUE): int
	{
		$builder = $this->build($build_callback);

		$builder->select('count(DISTINCT this)');
		$builder->resetDQLPart('orderBy');

		if ($non_limited) {
			$builder->setMaxResults(NULL);
			$builder->setFirstResult(0);
		}

		return $cache
			? $builder->getQuery()->getSingleScalarResult()
			: $builder->getQuery()->useQueryCache(FALSE)->getSingleScalarResult();
	}


	/**
	 * @param AbstractEntity $entity
	 */
	public function remove($entity, bool $flush = FALSE, bool $recompute = FALSE): AbstractRepository
	{
		$this->getEntityManager()->remove($entity);

		if ($flush) {
			$this->getEntityManager()->flush();
		}

		if ($recompute) {
			$this->getEntityManager()->getUnitOfWork()->computeChangeSet(
				$this->getEntityManager()->getClassMetadata(get_class($entity)),
				$entity
			);
		}

		return $this;
	}


	/**
	 * @param AbstractEntity $entity
	 * @return AbstractEntity
	 */
	public function replicate($entity)
	{
		return $this->replicator->clone($entity);
	}


	/**
	 * @param AbstractEntity $entity
	 */
	public function store($entity, bool $flush = FALSE, bool $recompute = FALSE): AbstractRepository
	{
		$this->getEntityManager()->persist($entity);

		if ($flush) {
			$this->getEntityManager()->flush();
		}

		if ($recompute) {
			$this->getEntityManager()->getUnitOfWork()->computeChangeSet(
				$this->getEntityManager()->getClassMetadata(get_class($entity)),
				$entity
			);
		}

		return $this;
	}


	/**
	 * @param AbstractEntity $entity
	 * @param array<string, mixed> $data
	 */
	public function update($entity, array $data, bool $protect = TRUE): AbstractRepository
	{
		$this->hydrator->fill($entity, $data, $protect);

		return $this;
	}


	/**
	 * @param callable|string|array<mixed> $build_callback
	 */
	protected function build($build_callback): QueryBuilder
	{
		$builder = $this->getEntityManager()->createQueryBuilder();

		$builder
			-> select('DISTINCT this')
			-> from(static::$entity, 'this')
		;

		if (is_callable($build_callback)) {
			$builder = $build_callback($builder);

		} else {
			if (!is_array($build_callback)) {
				$build_callback = array($build_callback);
			}

			foreach ($build_callback as $method) {
				if (!is_callable($method)) {
					$method = [$this, 'build' . ucfirst($method)];
				}

				if (is_callable($method)) {
					$builder = $method($builder);
				}
			}
		}

		return $builder;
	}


	/**
	 * @return Collections\Collection<int, AbstractEntity>
	 */
	protected function collect(Query ...$queries): Collections\Collection
	{
		$collection = new static::$collection([]);

		foreach ($queries as $query) {
			$collection = new static::$collection(array_merge(
				$collection->toArray(),
				$query->getResult()
			));
		}

		return $collection;
	}


	/**
	 * @param array<int, string> $paths
	 */
	protected function join(QueryBuilder $builder, array $paths = array()): QueryBuilder
	{
		foreach ($paths as $path) {
			if (strpos($path, '.') === FALSE) {
				continue;
			}

			$alias = explode('.', $path, 2)[0];
			$joins = array_filter(
				$builder->getDQLPart('join'),
				function($join_sql) use ($alias) {
					foreach ($join_sql as $join) {
						if (explode('.', $join->getJoin(), 2)[1] == $alias) {
							return TRUE;
						}
					}

					return FALSE;
				}
			);

			if (!count($joins)) {
				$builder->leftJoin(sprintf('this.%s', $alias), $alias, 'ON');
				$builder->addSelect($alias);
			}
		}

		return $builder;
	}


	/**
	 * @param array<string, string> $order
	 */
	protected function order(QueryBuilder $builder, array $order = array()): QueryBuilder
	{
		$builder = $this->join($builder, array_keys($order));

		foreach ($order as $path => $dir) {
			if (strpos($path, '.') === FALSE) {
				$path = sprintf('this.%s', $path);
			}

			$orders = array_filter(
				$builder->getDQLPart('orderBy'),
				function($order_sql) use ($path) {
					foreach ($order_sql->getParts() as $order) {
						if (explode(' ', $order, 2)[0] == $path) {
							return TRUE;
						}
					}

					return FALSE;
				}
			);

			if (!count($orders)) {
				$builder->addOrderBy($path, $dir);
			}
		}

		return $builder;
	}
}

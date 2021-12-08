<?php

namespace Hiraeth\Doctrine;

use InvalidArgumentException;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections;

/**
 *
 */
abstract class AbstractRepository extends EntityRepository
{
	/**
	 *
	 */
	protected static $entity = NULL;


	/**
	 *
	 */
	protected static $collection = Collection::class;


	/**
	 *
	 */
	protected static $order = [];


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
	 *
	 */
	public function attach($entity): AbstractRepository
	{
		$this->_em->merge($entity);

		return $this;
	}


	/**
	 *
	 */
	public function create(array $data = array(), bool $protect = TRUE): AbstractEntity
	{

		$entity = new static::$entity;

		$this->update($entity, $data, $protect);

		return $entity;
	}


	/**
	 *
	 */
	public function detach($entity): AbstractRepository
	{
		$this->_em->detach($entity);

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
	 * @param array $order_by The order by clause to add
	 * @return Collections\Collection
	 */
	public function findAll(?array $order_by = []): Collections\Collection
	{
		return $this->findBy([], $order_by);
	}


	/**
	 * {@inheritDoc}
	 * @return Collections\Collection
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
	  *
	 */
	public function query($build_callback, &$nonlimited_count = NULL): Collections\Collection
	{
		$builder = $this->build($build_callback);

		if (in_array('DISTINCT this', $builder->getDQLPart('select')[0]->getParts())) {
			$builder = $this->order($builder, static::$order);
		}

		if (func_num_args() == 2) {
			$nonlimited_count = $this->queryCount(function() use ($builder) {
				return clone $builder;
			}, TRUE);
		}

		return $this->collect($builder->getQuery());
	}


	/**
	 *
	 */
	public function queryCount($build_callback, $non_limited = FALSE): int
	{
		$builder = $this->build($build_callback);

		$builder->select('count(DISTINCT this)');
		$builder->resetDQLPart('orderBy');

		if ($non_limited) {
			$builder->setMaxResults(NULL);
			$builder->setFirstResult(0);
		}

		return $builder->getQuery()->getSingleScalarResult();
	}


	/**
	 *
	 */
	public function remove($entity, $flush = FALSE, $recompute = FALSE): AbstractRepository
	{
		$this->_em->remove($entity);

		if ($flush) {
			$this->_em->flush();
		}

		if ($recompute) {
			$this->_em->getUnitOfWork()->computeChangeSet(
				$this->_em->getClassMetadata(get_class($entity)),
				$entity
			);
		}

		return $this;
	}


	/**
	 *
	 */
	public function replicate($entity)
	{
		return $this->replicator->clone($entity);
	}


	/**
	 *
	 */
	public function store($entity, $flush = FALSE, $recompute = FALSE): AbstractRepository
	{
		$this->_em->persist($entity);

		if ($flush) {
			$this->_em->flush();
		}

		if ($recompute) {
			$this->_em->getUnitOfWork()->computeChangeSet(
				$this->_em->getClassMetadata(get_class($entity)),
				$entity
			);
		}

		return $this;
	}


	/**
	 *
	 */
	public function update(AbstractEntity $entity, array $data, bool $protect = TRUE): AbstractRepository
	{
		$this->hydrator->fill($entity, $data, $protect);

		return $this;
	}


	/**
	 *
	 */
	protected function build($build_callback): QueryBuilder
	{
		$builder = $this->_em
			-> createQueryBuilder()
			-> select('DISTINCT this')
			-> from(static::$entity, 'this')
		;

		if (is_callable($build_callback)) {
			$builder = $build_callback($builder);
		} elseif (is_string($build_callback) || is_array($build_callback)) {
			settype($build_callback, 'array');

			foreach ($build_callback as $method) {
				if (!is_callable($method)) {
					$method = [$this, 'build' . ucfirst($method)];
				}

				$builder = $method($builder);
			}
		} else {
			throw new InvalidArgumentException('Invalid builder type');
		}

		return $builder;
	}


	/**
	 *
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
	 *
	 */
	protected function join(QueryBuilder $builder, array $paths = array())
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
	 *
	 */
	protected function order(QueryBuilder $builder, array $order = array())
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

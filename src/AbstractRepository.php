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
	 *
	 */
	public function find($id, $lock_mode = NULL, $lock_version = NULL)
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
	public function findAll(?array $order_by = [])
	{
		if (!is_null($order_by)) {
			$order_by = array_merge($order_by, static::$order);
		}

		return $this->findBy([], $order_by);
	}


	/**
	 * {@inheritDoc}
	 */
	public function findBy(array $criteria, ?array $order_by = [], $limit = null, $offset = null)
	{
		$persister = $this->_em->getUnitOfWork()->getEntityPersister($this->_entityName);

		if (!is_null($order_by)) {
			$order_by = array_merge($order_by, static::$order);
		}

		foreach ($criteria as $key => $value) {

			//
			// This enables us to pass a collection as an array for `IN()` criteria
			//

			if ($value instanceof Collections\Collection) {
				$criteria[$key] = $value->getValues();
			}
		}

		return new static::$collection($persister->loadAll($criteria, $order_by, $limit, $offset));
	}


	/**
	 * {@inheritDoc}
	 */
	public function findOneBy(array $criteria, ?array $order_by = [])
	{
		if (!is_null($order_by)) {
			$order_by = array_merge($order_by, static::$order);
		}

		return parent::findOneBy($criteria, $order_by);
	}


	/**
	  *
	 */
	public function query($build_callback, &$nonlimited_count = NULL): Collections\Collection
	{
		$builder      = $this->build($build_callback);
		$order_parts  = $builder->getDQLPart('orderBy');
		$select_parts = $builder->getDQLPart('select');

		if (empty($order_parts) && in_array('DISTINCT this', $select_parts[0]->getParts())) {
			foreach (static::$order as $property => $direction) {
				$builder->addOrderBy('this.' . $property, $direction);
			}
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
	public function remove($entity, $flush = FALSE): AbstractRepository
	{
		$this->_em->remove($entity);

		if ($flush) {
			$this->_em->flush($entity);
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
			$this->_em->flush($entity);
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
}

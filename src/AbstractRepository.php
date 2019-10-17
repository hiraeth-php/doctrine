<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface as EntityManager;
use Doctrine\Common\Collections;
use InvalidArgumentException;

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
	public function __construct(ManagerRegistry $registry)
	{
		$manager   = $registry->getManagerForClass(static::$entity);
		$meta_data = $manager->getClassMetaData(static::$entity);

		parent::__construct($manager, $meta_data);
	}


	/**
	 *
	 */
	public function collect(Query ...$queries)
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
	public function create()
	{
		return new static::$entity;
	}


	/**
	  *
	 */
	public function build($build_callback, &$nonlimited_count = NULL)
	{
		$builder = $this->_em
			-> createQueryBuilder()
			-> select('this')
			-> from(static::$entity, 'this')
		;

		foreach (static::$order as $property => $direction) {
			$builder->addOrderBy('this.' . $property, $direction);
		}

		if (is_callable($build_callback)) {
			$build_callback($builder);
		} elseif (is_string($build_callback) || is_array($build_callback)) {
			settype($build_callback, 'array');

			foreach ($build_callback as $method) {
				if (!is_callable($method)) {
					$method = [$this, 'query' . ucfirst($method)];
				}

				$method($builder);
			}
		} else {
			throw new InvalidArgumentException('Invalid builder type');
		}

		if (func_num_args() == 2) {
			$nonlimited_count = $this->buildCount($builder, TRUE);
		}

		return $builder->getQuery();
	}


	/**
	 *
	 */
	public function buildCount(QueryBuilder $builder, $non_limited = FALSE)
	{
		$bclone = clone $builder;

		$bclone->select('count(this)');
		$bclone->resetDQLPart('orderBy');

		if ($non_limited) {
			$bclone->setMaxResults(NULL);
			$bclone->setFirstResult(0);
		}

		return $bclone->getQuery()->getSingleScalarResult();
	}


	/**
	 * Standard findAll with the option to add an orderBy
	 *
	 * {@inheritDoc}
	 * @param array $orderBy The order by clause to add
	 */
	public function findAll(array $orderBy = array())
	{
		return $this->findBy([], $orderBy);
	}


	/**
	 * {@inheritDoc}
	 */
	public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
	{
		$orderBy   = array_merge((array) $orderBy, static::$order);
		$persister = $this->_em->getUnitOfWork()->getEntityPersister($this->_entityName);

		foreach ($criteria as $key => $value) {

			//
			// This enables us to pass a collection as an array for `IN()` criteria
			//

			if ($value instanceof Collections\Collection) {
				$criteria[$key] = $value->getValues();
			}
		}

		return new static::$collection($persister->loadAll($criteria, $orderBy, $limit, $offset));
	}


	/**
	 * {@inheritDoc}
	 */
	public function findOneBy(array $criteria, array $orderBy = null)
	{
		$orderBy = array_merge((array) $orderBy, static::$order);

		return parent::findOneBy($criteria, $orderBy);
	}


	/**
	 *
	 */
	public function merge($entity)
	{
		$this->_em->merge($entity);
	}


	/**
	 *
	 */
	public function remove($entity)
	{
		$this->_em->remove($entity);
	}


	/**
	 *
	 */
	public function store($entity, $flush = FALSE)
	{
		$this->_em->persist($entity);

		if ($flush) {
			$this->_em->flush($entity);
		}
	}
}

<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections;

/**
 * @template Entity of object
 * @extends EntityRepository<Entity>
 */
abstract class AbstractRepository extends EntityRepository
{
	/**
	 * @var class-string<Collections\Collection>
	 */
	static protected $collection = Collection::class;

	/**
	 * @var class-string<Entity>
	 */
	static protected $entity;

	/**
	 * @var array<string, string>
	 */
	static protected $order = [];

	/**
	 * @var Hydrator
	 */
	protected $hydrator;


	/**
	 * @var EntityManager
	 */
	protected $manager;


	/**
	 * @var ManagerRegistry
	 */
	protected $registry;


	/**
	 * @var Replicator<Entity>
	 */
	protected $replicator;


	/**
	 * Construct a new repository
	 *
	 * @param ManagerRegistry $registry
	 * @param Hydrator $hydrator
	 * @param Replicator<Entity> $replicator
	 */
	public function __construct(ManagerRegistry $registry, Hydrator $hydrator, Replicator $replicator)
	{

		$this->registry   = $registry;
		$this->hydrator   = $hydrator;
		$this->replicator = $replicator;
		$this->manager    = $this->registry->getManagerForClass(static::$entity);

		parent::__construct($this->manager, $this->manager->getClassMetadata(static::$entity));
	}


	/**
	 * Attach an entity to the repository.
	 *
	 * Using this method you can attach/re-attach a detached entity to the repository by merging
	 * it into the existing entity manager state.
	 *
	 * @param Entity $entity
	 * @return self<Entity>
	 */
	public function attach(object $entity): AbstractRepository
	{
		$this->manager->merge($entity);

		return $this;
	}


	/**
	 * Create a new entity.
	 *
	 * @param array<string, mixed> $data
	 * @param bool $protect
	 * @return Entity
	 */
	public function create(array $data = array(), bool $protect = TRUE): object
	{

		$entity = new static::$entity;

		if (!empty($data)) {
			$this->update($entity, $data, $protect);
		}

		return $entity;
	}


	/**
	 * Detach an entity from the repository.
	 *
	 * A detached entity allows you to make changes without persisting them to the database.  If
	 * it is determined that you do want to persist the changes, use the attach() method to
	 * merge it back into the repository.
	 *
	 * @param Entity $entity The entity to detach.
	 * @return self<Entity> The repository instance for method chaining.
	 */
	public function detach(object $entity): self
	{
		$this->manager->detach($entity);

		return $this;
	}


	/**
	 * {@inheritDoc}
	 *
	 * @return Entity|null
	 */
	public function find($id, $lock_mode = NULL, $lock_version = NULL): ?object
	{
		if (!is_null($id)) {
			if (is_scalar($id)) {
				return parent::find($id, $lock_mode, $lock_version) ?: NULL;
			}

			if (is_array($id) && array_keys($id)) {
				$meta_data   = $this->getClassMetadata();
				$field_names = $meta_data->getIdentifierFieldNames();
				$identity    = array_intersect_key($id, array_flip($field_names));

				if (count($identity) == count($field_names)) {
					$id = $identity;
				}

				$result = $this->findBy($id, [], 2);

				if (count($result) > 1) {
					throw new \InvalidArgumentException(sprintf(
						'ID argument with keys "%s" yields more than one result',
						join(', ', array_keys($id))
					));
				}

				return $result->first() ?: NULL;
			}
		}

		return NULL;
	}


	/**
	 * {@inheritDoc}
	 *
	 * @return Collection<int, Entity>
	 */
	public function findAll(?array $order_by = []): Collection
	{
		return $this->findBy([], $order_by);
	}


	/**
	 * {@inheritDoc}
	 *
	 * @return Collection<int, Entity>
	 */
	public function findBy(array $criteria, ?array $order_by = [], $limit = null, $offset = null, ?int &$nonlimited_count = NULL): Collection
	{
		if (!is_null($order_by)) {
			$order_by = $order_by + static::$order;
		}

		return $this->query(function ($builder) use ($criteria, $order_by, $limit, $offset) {
			$param    = 1;
			$criteria = $this->join($builder, $criteria);
			$order_by = $this->order($builder, $order_by);

			foreach ($criteria as $key => $value) {
				$parts  = array_diff(explode('.', $key), ['this']);
				$method = sprintf('filter%s', implode(array_map('ucfirst', $parts)));

				if (method_exists($this, $method)) {
					if ($value === '' || is_array($value) && !array_filter($value)) {
						continue;
					}

					$this->$method($builder, $value);

				} else {
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
			}

			if (!is_null($limit)) {
				$builder->setMaxResults($limit);
			}

			if (!is_null($offset)) {
				$builder->setFirstResult($offset);
			}

			return $builder;
		}, $nonlimited_count);
	}


	/**
	 * {@inheritDoc}
	 *
	 * @return Entity|null
	 */
	public function findOneBy(array $criteria, ?array $order_by = []): ?object
	{
		return $this->findBy($criteria, $order_by, 1)->first() ?: NULL;
	}


	/**
	 * Query the repository using a build callback.
	 *
	 * @param callable|string|array<callable|string> $build_callbacks
	 * @return Collection<int, Entity> The collection of entities matching the query builder.
	 */
	public function query($build_callbacks, ?int &$nonlimited_count = NULL, bool $cache = TRUE): Collection
	{
		$builder = $this->build($build_callbacks);
		$selects = $builder->getDQLPart('select');

		if ((string) $selects[0] == 'DISTINCT this') {
			$this->order($builder, static::$order);

			foreach ($builder->getDQLPart('orderBy') as $order_expr) {
				$alias = substr($order_expr, 0, strpos($order_expr, '.'));

				if ($alias !== 'this') {
					$order_on = substr($order_expr, 0, strpos($order_expr, ' '));
					$matches  = array_filter(
						$selects,
						function($select_expr) use ($alias, $order_on) {
							if ((string) $select_expr == $alias) {
								return TRUE;
							}

							$is_hidden = strpos(strtoupper($select_expr), 'AS HIDDEN') !== FALSE;
							$is_match  = strpos($select_expr, $order_on) === 0;

							if ($is_hidden && $is_match) {
								return TRUE;
							}

							return FALSE;
						}
					);

					if (!count($matches)) {
						$builder->addSelect($alias);
					}
				}
			}
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
	 * Count the number of entities in a repository using a build callback.
	 *
	 * @param callable|string|array<callable|string> $build_callbacks
	 * @return mixed The number of entities matching the query builder.
	 */
	public function queryCount($build_callbacks, bool $non_limited = FALSE, bool $cache = TRUE)
	{
		$builder    = $this->build($build_callbacks);
		$meta_data  = $this->getClassMetadata();
		$identifier = $meta_data->getIdentifierFieldNames();

		$builder->resetDQLPart('orderBy');
		$builder->select($builder->expr()->countDistinct(...array_map(
			function($field) {
				return sprintf('this.%s', $field);
			},
			$identifier
		)));

		if ($non_limited) {
			$builder->setMaxResults(NULL);
			$builder->setFirstResult(0);
		}

		return $cache
			? $builder->getQuery()->getSingleScalarResult()
			: $builder->getQuery()->useQueryCache(FALSE)->getSingleScalarResult();
	}


	/**
	 * Remove an entity from the repository.
	 *
	 * @param Entity $entity The entity to remove.
	 * @param bool $flush Whether or not to flush the entity manager immediately.
	 * @param bool $recompute Whether or not to recompute the unit of work on entity changeset.
	 * @return self<Entity> The repository instance for method chaining.
	 */
	public function remove(object $entity, bool $flush = FALSE, bool $recompute = FALSE)
	{
		$this->manager->remove($entity);

		if ($flush) {
			$this->manager->flush();
		}

		if ($recompute) {
			$this->manager->getUnitOfWork()->computeChangeSet(
				$this->manager->getClassMetadata(get_class($entity)),
				$entity
			);
		}

		return $this;
	}


	/**
	 * Replicate an entity using the replicator which will do smart deep cloning.
	 *
	 * @param Entity $entity The entity to replicate.
	 * @return Entity|null The replicated entity.
	 */
	public function replicate(object $entity): ?object
	{
		return $this->replicator->clone($entity);
	}


	/**
	 * Store an entity in the repository by persisting it, or flush all changes
	 *
	 * @param Entity|null $entity The entity to store.
	 * @param bool $flush Whether or not to flush the entity manager immediately.
	 * @param bool $recompute Whether or not to recompute the unit of work on entity changeset.
	 * @return self<Entity> The repository instance for method chaining.
	 */
	public function store(?object $entity = NULL, bool $flush = FALSE, bool $recompute = FALSE): AbstractRepository
	{
		if ($entity) {
			$this->manager->persist($entity);
		}

		if ($flush || func_num_args() === 0) {
			$this->manager->flush();
		}

		if ($recompute) {
			$this->manager->getUnitOfWork()->computeChangeSet(
				$this->manager->getClassMetadata(get_class($entity)),
				$entity
			);
		}

		return $this;
	}


	/**
	 * Update an entity using the hydrator
	 *
	 * @param Entity $entity
	 * @param array<string, mixed> $data
	 * @return self<Entity> The repository instance for method chaining.
	 */
	public function update(object $entity, array $data, bool $protect = TRUE): AbstractRepository
	{
		$this->hydrator->fill($entity, $data, $protect);

		return $this;
	}


	/**
	 * Initiate build and execute callbacks
	 *
	 * @param callable|string|array<mixed> $build_callbacks
	 */
	protected function build($build_callbacks): QueryBuilder
	{
		$builder = $this->manager->createQueryBuilder();

		$builder
			->select('DISTINCT this')
			->from(static::$entity, 'this')
		;

		if (is_callable($build_callbacks)) {
			$builder = $build_callbacks($builder);

		} else {
			if (!is_array($build_callbacks)) {
				$build_callbacks = array($build_callbacks);
			}

			foreach ($build_callbacks as $method) {
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
	 * Collect one or more querie results into a single custom collection
	 *
	 * @return Collection<int, Entity>
	 */
	protected function collect(Query ...$queries): Collection
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
	 * Add joining to a builder based on criteria
	 *
	 * @param QueryBuilder $builder
	 * @param array<int, mixed> $data
	 * @return array<mixed>
	 */
	protected function join(QueryBuilder $builder, array $data = array()): array
	{
		$result    = array();
		$path_data = $this->pathize($data);

		foreach ($path_data as $path => $value) {
			$parts = explode('.', $path);
			$path  = implode('.', array_slice($parts, -2));

			if (count($parts) > 2) {
				for ($x = 0; $x < count($parts); $x++) {
					if (!isset($parts[$x+2])) {
						break;
					}

					$alias = $parts[$x+1];
					$joins = array_filter(
						$builder->getDQLPart('join'),
						function($join_expr) use ($alias) {
							foreach ($join_expr as $join) {
								if (explode('.', $join->getJoin(), 2)[1] == $alias) {
									return TRUE;
								}
							}

							return FALSE;
						}
					);

					if (!count($joins)) {
						$builder->leftJoin(sprintf('%s.%s', $parts[$x], $alias), $alias, 'ON');
					}
				}
			}

			$result[$path] = $value;
		}

		return $result;
	}


	/**
	 * Add ordering to a builder based on criteria
	 *
	 * @param QueryBuilder $builder
	 * @param array<string, string> $order
	 * @return array<string>
	 */
	protected function order(QueryBuilder $builder, array $order = array()): array
	{
		$result = array();
		$order  = $this->join($builder, $order);

		foreach ($order as $path => $value) {
			$parts  = explode('.', $path);
			$path   = implode('.', array_slice($parts, -2));
			$orders = array_filter(
				$builder->getDQLPart('orderBy'),
				function($order_expr) use ($path) {
					foreach ($order_expr->getParts() as $order) {
						if (explode(' ', $order, 2)[0] == $path) {
							return TRUE;
						}
					}

					return FALSE;
				}
			);

			if (!count($orders)) {
				$builder->addOrderBy($path, $value);
			}

			$result[$path] = $value;
		}

		return $result;
	}


	/**
	 * Normalize the paths in a criteria
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	protected function pathize(array $data, string $prefix = 'this'): array
	{
		$result = array();

		foreach ($data as $key => $value) {
			if (strpos($key, $prefix . '.') !== 0) {
				$key = $prefix . '.' . $key;
			}

			if (is_array($value)) {
				if (!is_numeric(array_key_first($value))) {
					$result = array_merge($result, $this->pathize($value, $key));

					continue;
				}
			}

			if ($value instanceof Collections\Collection) {
				$value = $value->getValues();
			}

			$result[$key] = $value;
		}

		return $result;
	}
}

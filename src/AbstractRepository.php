<?php

namespace Hiraeth\Doctrine;

use RuntimeException;
use InvalidArgumentException;
use ReflectionProperty;
use ReflectionClass;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\EntityManagerInterface as EntityManager;
use Doctrine\Common\Collections;
use Doctrine\DBAL\Types\Type;

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
	protected static $reflections = [];


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
	public function attach($entity): AbstractRepository
	{
		$this->_em->merge($entity);

		return $this;
	}


	/**
	 *
	 */
	public function create(array $data = array()): AbstractEntity
	{

		$entity = new static::$entity;

		$this->update($entity, $data);

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

		return parent::find($id, $lock_mode, $lock_version);
	}


	/**
	 * Standard findAll with the option to add an orderBy
	 *
	 * {@inheritDoc}
	 * @param array $order_by The order by clause to add
	 */
	public function findAll(array $order_by = array())
	{
		return $this->findBy([], $order_by);
	}


	/**
	 * {@inheritDoc}
	 */
	public function findBy(array $criteria, array $order_by = null, $limit = null, $offset = null)
	{
		$order_by  = array_merge((array) $order_by, static::$order);
		$persister = $this->_em->getUnitOfWork()->getEntityPersister($this->_entityName);

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
	public function findOneBy(array $criteria, array $order_by = null)
	{
		$order_by = array_merge((array) $order_by, static::$order);

		return parent::findOneBy($criteria, $order_by);
	}


	/**
	  *
	 */
	public function query($build_callback, &$nonlimited_count = NULL): Collections\Collection
	{
		$builder = $this->build($build_callback);

		if (empty($builder->getDQLPart('orderBy'))) {
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
	public function queryCount($build_callback, $non_limited = FALSE): integer
	{
		$builder = $this->build($build_callback);

		$builder->select('count(this)');
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
	public function store($entity, $flush = FALSE): AbstractRepository
	{
		$this->_em->persist($entity);

		if ($flush) {
			$this->_em->flush($entity);
		}

		return $this;
	}


	/**
	 *
	 */
	public function update(AbstractEntity $entity, array $data): AbstractRepository
	{
		$this->updateProperties($entity, $data);
		$this->updateAssociations($entity, $data);

		return $this;
	}


	/**
	 *
	 */
	protected function build($build_callback): QueryBuilder
	{
		$builder = $this->_em
			-> createQueryBuilder()
			-> select('this')
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
	protected function reflectProperty(object $object, $name): ReflectionProperty
	{
		$class = get_class($object);

		if (!isset(static::$reflections[$class])) {
			static::$reflections[$class]['@'] = new ReflectionClass($class);
		}

		if (!isset(static::$reflections[$class][$name])) {
			static::$reflections[$class][$name] = static::$reflections[$class]['@']->getProperty($name);
			static::$reflections[$class][$name]->setAccessible(TRUE);
		}

		return static::$reflections[$class][$name];
	}



	/**
	 *
	 */
	protected function updateAssociations(object $object, array $data):  AbstractRepository
	{
		$meta_data = $this->getClassMetaData();

		foreach ($data as $field => $value) {
			if (array_intersect(['*', $field], $object::$_protect ?? ['*'])) {
				continue;
			}

			if (array_key_exists($field, $meta_data->associationMappings)) {
				$mapping = $meta_data->associationMappings[$field];

				switch ($mapping['type']) {
					case ClassMetadataInfo::ONE_TO_ONE:
					case ClassMetadataInfo::MANY_TO_ONE:
						$this->updateAssociationToOne($object, $field, $mapping, $value);
						break;

					case ClassMetadataInfo::ONE_TO_MANY:
					case ClassMetadataInfo::MANY_TO_MANY:
						$this->updateAssociationToMany($object, $field, $mapping, $value);
						break;

					default:
						throw new RuntimeException(sprintf(
							'Unknown mapping type "%s"',
							$mapping['type']
						));
				}
			}
		}

		return $this;
	}


	/**
	 *
	 */
	protected function updateAssociationToMany(object $object, string $field, array $mapping, $values):  AbstractRepository
	{
		$collection = new Collections\ArrayCollection();

		if (!empty($mapping)) {
			settype($values, 'array');

			foreach ($values as $value) {
				$related_entity = $this->getEntityManager()->find($mapping['targetEntity'], $value);

				if ($related_entity) {
					if (is_array($value)) {
						$this->update($related_entity, $value);
					}

					$collection->add($related_entity);
				}
			}

			$this->updateProperty($object, $field, $collection);
		}

		return $this;
	}


	/**
	 *
	 */
	protected function updateAssociationToOne(object $object, string $field, array $mapping, $value):  AbstractRepository
	{
		if (!empty($mapping)) {
			$related_entity = $this->getEntityManager()->find($mapping['targetEntity'], $value);

			if (is_array($value)) {
				$this->update($related_entity, $value);
			}

			$this->updateProperty($object, $field, $related_entity);
		}

		return $this;
	}


	/**
	 *
	 */
	protected function updateProperties(object $object, array $data, string $prefix = NULL): AbstractRepository
	{
		$meta_data = $this->getClassMetaData();
		$platform  = $this->getEntityManager()->getConnection()->getDatabasePlatform();

		foreach ($data as $field => $value) {
			$full_field = $prefix ? $prefix . '.' . $field : $field;

			if (array_intersect(['*', $full_field], $object::$_protect ?? ['*'])) {
				continue;
			}

			if (array_key_exists($full_field, $meta_data->fieldMappings)) {
				if (is_scalar($value)) {
					$type  = Type::getType($meta_data->fieldMappings[$full_field]['type'] ?? 'string');
					$value = $type->convertToPHPValue($value, $platform);

				}

				$this->updateProperty($object, $field, $value);
			}

			if (array_key_exists($full_field, $meta_data->embeddedClasses)) {
				$property   = $this->reflectProperty($object, $field);
				$embeddable = $property->getValue($object);

				if (!$embeddable) {
					$embeddable = new $meta_data->embeddedClasses[$field]['class']();
					$this->updateProperty($object, $field, $embeddable);
				}

				$this->updateProperties($embeddable, $value, $full_field);
			}
		}

		return $this;
	}


	/**
	 *
	 */
	protected function updateProperty(object $object, string $name, $value): AbstractRepository
	{
		$property = $this->reflectProperty($object, $name);

		if ($value instanceof Collections\Collection) {
			$collection = $property->getValue($name);

			foreach ($collection as $i => $entity) {
				if (!$value->contains($entity)) {
					$collection->remove($i);
				}
			}

			foreach ($value as $entity) {
				if (!$collection->contains($entity)) {
					$collection->add($entity);
				}
			}

		} else {
			$property->setValue($object, $value);
		}

		return $this;
	}
}

<?php

namespace Hiraeth\Doctrine;

use ReflectionClass;
use ReflectionProperty;
use ReflectionException;
use InvalidArgumentException;
use Doctrine\Common\Collections;

/**
 *
 */
trait PropertyAccess
{
	/**
	 *
	 */
	protected static $reflections = [];

	/**
	 * Find the property of a field.
	 */
	public function getProperty($entity, $name)
	{
		$value = NULL;

		if (strpos($name, '.')) {
			$parts = explode('.', $name);
			$name  = array_pop($parts);

			foreach ($parts as $part) {
				if (property_exists($entity, $part)) {
					$entity = $this->reflectProperty($entity, $part)->getValue($entity);
				}
			}
		}

		if (property_exists($entity, $name)) {
			$value = $this->reflectProperty($entity, $name)->getValue($entity);
		}

		return $value;
	}


	/**
	 * Set a property on a given entity using reflection if needed.
	 *
	 * If the property name is separated by dots, the entity will be resolved via reflection first
	 * and the final property will be set on the entity traversed to.
	 */
	public function setProperty(object $entity, string $name, $value): Hydrator
	{
		if (strpos($name, '.')) {
			$parts = explode('.', $name);
			$name  = array_pop($parts);

			foreach ($parts as $part) {
				if (property_exists($entity, $part)) {
					$entity = $this->reflectProperty($entity, $part)->getValue($entity);
				}
			}
		}

		if (property_exists($entity, $name)) {
			$method   = 'set' . ucwords($name);
			$property = $this->reflectProperty($entity, $name);
			$existing = $property->getValue($entity);

			//
			// Note: this should only be employed for basic collection mapping.  Associations
			// are more appropriately handled by the fillAssociation* methods.  This is, however,
			// important for if collections are used in place of arrays -- particularly for
			// serialization and deserialization.
			//

			if ($existing instanceof Collections\Collection) {
				if ($value instanceof Collections\Collection) {
					$value = $value->toArray();
				} else {
					settype($value, 'array');
				}

				foreach ($existing as $i => $entity) {
					if (!in_array($entity, $value, TRUE)) {
						$existing->remove($i);
					}
				}

				foreach ($value as $entity) {
					if (!$existing->contains($entity)) {
						$existing->add($entity);
					}
				}
			} elseif (!is_callable([$entity, $method])) {
				$this->reflectProperty($entity, $name)->setValue($entity, $value);
			} else {
				$entity->$method($value);
			}
		}

		return $this;
	}


	/**
	 *
	 */
	protected function reflectProperty(object $entity, $name): ReflectionProperty
	{
		$class = get_class($entity);

		if (!isset(static::$reflections[$class])) {
			static::$reflections[$class]['@'] = new ReflectionClass($class);
		}

		if (!isset(static::$reflections[$class][$name])) {
			try {
				static::$reflections[$class][$name] = static::$reflections[$class]['@']->getProperty($name);
				static::$reflections[$class][$name]->setAccessible(TRUE);
			} catch (ReflectionException $e) {
				throw new InvalidArgumentException(sprintf(
					'Cannot set property, class "%s" has no property named "%s"',
					$class,
					$name
				));
			}
		}

		return static::$reflections[$class][$name];
	}
}

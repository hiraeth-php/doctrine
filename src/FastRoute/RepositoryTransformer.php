<?php

namespace Hiraeth\Doctrine;

use Hiraeth\FastRoute;
use Hiraeth\Application;

use Auryn\InjectorException;

/**
 * A repository transformer is responsible for tranformation a parameter/string representation of a
 * repository from a URL into an actual repository and vice versa.
 */
class RepositoryTransformer implements FastRoute\Transformer
{
	const REGEX_SPLIT = '/((?<=[a-z])([A-Z])|(?<=[A-Z])([A-Z])(?=([a-rt-z]|s[a-z])))/';

	/**
	 * @var Application
	 */
	protected $app;


	/**
	 * Construct a new tool transformer.
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}


	/**
	 * {@inheritDoc}
	 */
	public function fromUrl($name, $value, array $context = array())
	{
		$parts = explode('/', $value);
		$class = implode('\\', array_map(
			function($part) {
				return str_replace(' ', '', ucwords(str_replace('-', ' ', $part)));
			},
			$parts
		));

		try {
			return $this->app->get($class);

		} catch (InjectorException $e) {
			if ($this->app->isDebugging()) {
				throw $e;
			}

			return NULL;
		}
	}


	/**
	 * {@inheritDoc}
	 */
	public function toUrl($name, $value, array $context = array()): string
	{
		$class = get_class($value);
		$parts = explode('\\', $class);

		return implode('/', array_map(
			function($part) {
				return strtolower(preg_replace(static::REGEX_SPLIT, '-$1', $part));
			},
			$parts
		));
	}
}

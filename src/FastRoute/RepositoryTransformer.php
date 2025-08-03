<?php

namespace Hiraeth\Doctrine\FastRoute;

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
	public function fromUrl(string $name, string $value, array $context = []): mixed
	{
		$parts = explode('/', $value);
		$class = implode('\\', array_map(
			fn($part) => str_replace(' ', '', ucwords(str_replace('-', ' ', $part))),
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
	public function toUrl(string $name, mixed $value, array $context = []): string
	{
		$class = $value::class;
		$parts = explode('\\', $class ?: 'Unknown');

		return implode('/', array_map(
			fn($part) => strtolower(preg_replace(static::REGEX_SPLIT, '-$1', (string) $part)),
			$parts
		));
	}
}

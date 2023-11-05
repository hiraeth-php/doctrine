<?php

namespace Hiraeth\Doctrine;

/**
 *
 */
abstract class AbstractEntity
{
	/**
	 * An array of protected properties.
	 *
	 * @var array<string>
	 */
	static public $_protect = ['*'];

	/**
	 *
	 */
	public function __construct()
	{
		//
		// Placeholder for default configs
		//
	}
}

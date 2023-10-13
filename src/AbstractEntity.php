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
	static public $_protect = array();

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

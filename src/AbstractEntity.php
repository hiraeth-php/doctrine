<?php

namespace Hiraeth\Doctrine;

/**
 *
 */
abstract class AbstractEntity
{
	/**
	 * An array of fillable properties.
	 *
	 * @var array<string>
	 */
	static public $fillable = [];

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

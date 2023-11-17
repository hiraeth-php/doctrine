<?php

namespace Hiraeth\Doctrine;

/**
 *
 */
abstract class AbstractEntity
{
	/**
	 * List of expandable properties during JSON normalization
	 *
	 * @var array
	 */
	static public $expandable = [];

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

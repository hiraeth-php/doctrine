<?php

namespace Hiraeth\Doctrine;

class NumericFilter
{
	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public function __invoke($value)
	{
		if (is_numeric($value)) {
			return $value;
		}

		return NULL;
	}
}

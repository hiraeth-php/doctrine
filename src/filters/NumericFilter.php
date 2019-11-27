<?php

namespace Hiraeth\Doctrine;

class NumericFilter
{
	public function __invoke($value)
	{
		if (is_numeric($value)) {
			return $value;
		}

		return NULL;
	}
}

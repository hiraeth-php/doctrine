<?php

namespace Hiraeth\Doctrine;

class StringFilter
{
	public function __invoke($value)
	{
		if (trim($value)) {
			return $value;
		}

		return NULL;
	}
}

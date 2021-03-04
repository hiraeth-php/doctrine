<?php

namespace Hiraeth\Doctrine;

class StringFilter
{
	public function __invoke($value)
	{
		if (strlen(trim($value))) {
			return $value;
		}

		return NULL;
	}
}

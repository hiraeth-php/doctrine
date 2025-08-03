<?php

namespace Hiraeth\Doctrine;

class StringFilter
{
	/**
	 * @param mixed $value
	 * @return string|null
	 */
	public function __invoke($value)
	{
		if (strlen(trim((string) $value))) {
			return $value;
		}

		return NULL;
	}
}

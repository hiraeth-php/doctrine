<?php

namespace Hiraeth\Doctrine;

class BooleanFilter
{
	/**
	 * @param mixed $value
	 * @return bool|null
	 */
	public function __invoke($value)
	{
		if (in_array($value, ['t', 'true', 'y', 'yes', 'on', '1', TRUE], TRUE)) {
			return TRUE;
		}

		if  (in_array($value, ['f', 'false', 'n', 'no', 'off', '0', FALSE], TRUE)) {
			return FALSE;
		}

		return NULL;
	}
}

<?php

namespace Hiraeth\Doctrine;

use DateTime;

class DateTimeFilter
{
	public function __invoke($value)
	{
		if ($value) {
			try {
				return new DateTime($value);
			} catch (\Exception $e) {

			}
		}

		return NULL;
	}
}

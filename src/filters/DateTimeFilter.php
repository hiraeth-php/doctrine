<?php

namespace Hiraeth\Doctrine;

use DateTime;

class DateTimeFilter
{
	public function __invoke($value)
	{
		if ($value) {
			//
			// Allow for accepting year-only strings
			//

			if (is_string($value) && is_numeric($value) && strlen($value) == 2) {
				$value = sprintf('01/01/%s', $value);
			}

			try {
				return new DateTime($value);

			} catch (\Exception $e) {
				//
				// If the parsing fails we'll hit the NULl return below
				//
			}
		}

		return NULL;
	}
}

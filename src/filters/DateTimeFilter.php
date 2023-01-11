<?php

namespace Hiraeth\Doctrine;

use DateTime;

class DateTimeFilter
{
	/**
	 * @param mixed $value
	 * @return DateTime|null
	 */
	public function __invoke($value)
	{
		if ($value) {
			//
			// Allow for accepting year-only strings
			//

			if (is_string($value) && is_numeric($value) && in_array(strlen($value), [2, 4])) {
				$value = sprintf('01/01/%s', $value);
			}

			try {
				if ($value instanceof DateTime) {
					return $value;

				} else {
					return new DateTime($value);

				}

			} catch (\Exception $e) {
				//
				// If the parsing fails we'll hit the NULl return below
				//
			}
		}

		return NULL;
	}
}

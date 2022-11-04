<?php

namespace Hiraeth\Doctrine;

use SplFileInfo;

class FileFilter
{
	/**
	 * @param mixed $value
	 * @return SplFileInfo|null
	 */
	public function __invoke($value)
	{
		if ($value) {
			return new SplFileInfo($value);
		}

		return NULL;
	}
}

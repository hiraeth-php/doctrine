<?php

namespace Hiraeth\Doctrine;

use SplFileInfo;

class FileFilter
{
	public function __invoke($value)
	{
		if ($value) {
			return new SplFileInfo($value);
		}

		return NULL;
	}
}

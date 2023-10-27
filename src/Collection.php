<?php

namespace Hiraeth\Doctrine;

use InvalidArgumentException;
use Doctrine\Common\Collections;

/**
 * @template Key of int
 * @template Entity of object
 * @extends Collections\ArrayCollection<Key, Entity>
 */
class Collection extends Collections\ArrayCollection
{
	use PropertyAccess;

	/**
	 * @param array<string, string> $config
	 * @return Collection<int, Entity>
	 */
	public function order(array $config): Collection
	{
		$data = $this->toArray();

		usort($data, function($a, $b) use ($config) {
			foreach ($config as $property => $dir) {
				$a_val = $this->getProperty($a, $property);
				$b_val = $this->getProperty($b, $property);
				$dir   = strtolower($dir);

				if (!in_array($dir, ['desc', 'asc'])) {
					throw new InvalidArgumentException(
						sprintf('Invalid direction %s specified', $dir)
					);
				}

				if ($dir == 'asc') {
					if ($a_val < $b_val) {
						return -1;
					}

					if ($a_val > $b_val) {
						return 1;
					}
				}

				if ($dir == 'desc') {
					if ($b_val < $a_val) {
						return -1;
					}

					if ($b_val > $a_val) {
						return 1;
					}
				}
			}

			return 0;
		});

		return new self($data);
	}
}

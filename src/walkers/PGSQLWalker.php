<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Query\SqlWalker;

class PGSQLWalker extends SqlWalker
{
	/**
	 * {@inheritDoc}
	 */
	public function walkLikeExpression(mixed $like): string
	{
		$sql = parent::walkLikeExpression($like);
		$sql = str_replace('LIKE', 'ILIKE', $sql);

		return $sql;
	}
}

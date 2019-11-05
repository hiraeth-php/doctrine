<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Query\SqlWalker;

class PGSQLWalker extends SqlWalker
{
	/**
	 *
	 */
	public function walkLikeExpression($like)
	{
		$sql = parent::walkLikeExpression($like);
		$sql = str_replace('LIKE', 'ILIKE', $sql);

		return $sql;
	}
}

<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command\SchemaTool;

/**
 *
 */
class SchemaCreateCommand extends SchemaTool\CreateCommand
{
	use MultipleEntityManagers;
}

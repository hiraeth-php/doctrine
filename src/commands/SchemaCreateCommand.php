<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command\SchemaTool\CreateCommand;

/**
 *
 */
class SchemaCreateCommand extends CreateCommand
{
	use MultipleEntityManagers;
}

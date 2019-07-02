<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command\SchemaTool;

/**
 *
 */
class SchemaDropCommand extends SchemaTool\DropCommand
{
	use MultipleEntityManagers;
}

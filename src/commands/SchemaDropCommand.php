<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command\SchemaTool\DropCommand;

/**
 *
 */
class SchemaDropCommand extends DropCommand
{
	use MultipleEntityManagers;
}

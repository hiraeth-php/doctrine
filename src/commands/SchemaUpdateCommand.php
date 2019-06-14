<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command\SchemaTool\UpdateCommand;

/**
 *
 */
class SchemaUpdateCommand extends UpdateCommand
{
	use MultipleEntityManagers;
}

<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command\SchemaTool;

/**
 *
 */
class SchemaUpdateCommand extends SchemaTool\UpdateCommand
{
	use MultipleEntityManagers;
}

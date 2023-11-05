<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command;

/**
 *
 */
class SchemaCreateCommand extends AbstractCommand
{
	/**
	 * {@inheritDoc}
	 */
	static protected $defaultName = 'orm:schema-tool:create';


	/**
	 * {@inheritDoc}
	 */
	static protected $proxy = Command\SchemaTool\CreateCommand::class;
}


<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command;

/**
 *
 */
class SchemaUpdateCommand extends AbstractCommand
{
	/**
	 * {@inheritDoc}
	 */
	static protected $defaultName = 'orm:schema-tool:update';


	/**
	 * {@inheritDoc}
	 */
	static protected $proxy = Command\SchemaTool\UpdateCommand::class;
}

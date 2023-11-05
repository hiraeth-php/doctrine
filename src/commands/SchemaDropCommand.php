<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command;

/**
 *
 */
class SchemaDropCommand extends AbstractCommand
{
	/**
	 * {@inheritDoc}
	 */
	static protected $defaultName = 'orm:schema-tool:drop';


	/**
	 * {@inheritDoc}
	 */
	static protected $proxy = Command\SchemaTool\DropCommand::class;

}

<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command;

/**
 *
 */
class GenerateProxiesCommand extends AbstractCommand
{
	/**
	 * {@inheritDoc}
	 */
	static protected $defaultName = 'orm:generate:proxies';


	/**
	 * {@inheritDoc}
	 */
	static protected $proxy = Command\GenerateProxiesCommand::class;
}

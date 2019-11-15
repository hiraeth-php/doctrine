<?php

namespace Hiraeth\Doctrine;

use Doctrine\ORM\Tools\Console\Command;

/**
 *
 */
class GenerateProxiesCommand extends Command\GenerateProxiesCommand
{
	use MultipleEntityManagers;

	protected static $defaultName = 'orm:generate:proxies';
}

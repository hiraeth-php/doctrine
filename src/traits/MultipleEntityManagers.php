<?php

namespace Hiraeth\Doctrine;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;

/**
 *
 */
trait MultipleEntityManagers
{
	/**
	 *
	 */
	public function __construct(ManagerRegistry $registry)
	{
		$this->registry = $registry;

		parent::__construct();
	}


	/**
	 *
	 */
	public function configure()
	{
		$this->addArgument('manager', InputArgument::OPTIONAL, 'The name of the entity manager to use');

		return parent::configure();
	}


	/**
	 *
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		$manager = $input->getArgument('manager');

		if ($manager) {
			$this->getHelperSet()->set(
				new EntityManagerHelper($this->registry->getManager($manager)),
				'em'
			);
		}

		return parent::execute($input, $output);
	}
}

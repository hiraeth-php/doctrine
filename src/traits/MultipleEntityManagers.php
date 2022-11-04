<?php

namespace Hiraeth\Doctrine;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;

/**
 *
 */
trait MultipleEntityManagers
{
	/**
	 * @var ManagerRegistry|null
	 */
	protected $registry = NULL;


	/**
	 *
	 */
	public function __construct(ManagerRegistry $registry)
	{
		$this->registry = $registry;

		parent::__construct();
	}


	/**
	 * @return void
	 */
	public function configure()
	{
		$this->addOption(
			'manager', 'm',
			InputOption::VALUE_REQUIRED,
			'The name of the entity manager to use',
			$this->registry->getDefaultManagerName()
		);

		parent::configure();

		$this->setName(static::$defaultName);
		$this->setAliases([]);
	}


	/**
	 *
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		$manager = $input->getOption('manager');

		if ($manager) {
			$this->getHelperSet()->set(
				new EntityManagerHelper($this->registry->getManager($manager)),
				'em'
			);
		}

		return parent::execute($input, $output);
	}
}

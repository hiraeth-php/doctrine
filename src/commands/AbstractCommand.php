<?php

namespace Hiraeth\Doctrine;

use Hiraeth\Console;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand extends Console\ProxyCommand
{
	/**
	 * @var array<string>
	 */
	static protected $excludeOptions = [
		'db-configuration',
		'configuration',
		'conn',
		'em',
	];


	/**
	 * @var array<string>
	 */
	static protected $excludePassthruOptions = [
		'manager'
	];


	/**
	 * @var ManagerRegistry
	 */
	protected $managers;

	/**
	 *
	 */
	public function __construct(ManagerRegistry $managers)
	{
		$this->managers = $managers;

		parent::__construct();
	}

	/**
	 *
	 */
	protected function configure(): void
	{
		$this
			->addOption(
				'manager', 'm',
				InputOption::VALUE_REQUIRED,
				'The name of the entity manager to use',
				$this->managers->getDefaultManagerName()
			)
		;

		parent::configure();
	}

	/**
	 *
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$command = new static::$proxy($this->managers);

		return $command->run($this->passthru($input, [
			'--em' => $input->getOption('manager')
		]), $output);
	}


	/**
	 *
	 */
	protected function proxy(): Command
	{
		return new static::$proxy($this->managers);
	}
}

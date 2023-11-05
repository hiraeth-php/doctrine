<?php

namespace Hiraeth\Doctrine;

use Hiraeth\Console;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
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
		$input->setOption('em', $input->getOption('manager'));

		$command = new static::$proxy($this->managers);

		return $command->run($this->passthru($input), $output);
	}
}

<?php

namespace Hiraeth\Doctrine;

use Doctrine\Common;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\LifecycleEventArgs;

use Psr\Log\LoggerInterface;

/**
 *
 */
class LogSubscriber implements Common\EventSubscriber
{

	/**
	 * @var LoggerInterface
	 */
	protected $logger;


	/**
	 *
	 */
	public function __construct(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}


	/**
	 * @return array<string>
	 */
	public function getSubscribedEvents()
	{
		return [
			Events::postPersist,
			Events::postRemove,
			Events::postUpdate,
		];
	}

	/**
	 * @return void
	 */
	public function postPersist(LifecycleEventArgs $args)
	{
		$this->logger->info('Entity has been persisted.', ['entity' => $args->getEntity()]);
	}

	/**
	 * @return void
	 */
	public function postRemove(LifecycleEventArgs $args)
	{
		$this->logger->info('Entity has been removed.', ['entity' => $args->getEntity()]);
	}

	/**
	 * @return void
	 */
	public function postUpdate(LifecycleEventArgs $args)
	{
		$this->logger->info('Entity has been updated.', ['entity' => $args->getEntity()]);
	}
 }

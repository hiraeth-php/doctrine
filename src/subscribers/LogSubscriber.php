<?php

namespace Hiraeth\Doctrine;

use Doctrine\Common;
use Doctrine\ORM\Event;
use Doctrine\ORM\Events;

use Doctrine\Common\Persistence\Event\LifecycleEventArgs;

use Psr\Log\LoggerInterface;

/**
 *
 */
class LogSubscriber implements Common\EventSubscriber
{

	/**
	 *
	 */
	protected $logger = array();


	/**
	 *
	 */
	public function __construct(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}


	/**
	 *
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
	 *
	 */
	public function postPersist(LifecycleEventArgs $args)
	{
		$this->logger->info('Entity has been persisted.', ['entity' => $args->getEntity()]);
	}

	/**
	 *
	 */
	public function postRemove(LifecycleEventArgs $args)
	{
		$this->logger->info('Entity has been removed.', ['entity' => $args->getEntity()]);
	}

	/**
	 *
	 */
	public function postUpdate(LifecycleEventArgs $args)
	{
		$this->logger->info('Entity has been updated.', ['entity' => $args->getEntity()]);
	}
 }

<?php

namespace Hiraeth\Doctrine;

use Doctrine\Common;
use Doctrine\ORM\Event;
use Doctrine\ORM\Events;
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
	public function postPersist(Event\PostPersistEventArgs $args)
	{
		$this->logger->info('Entity has been persisted.', ['entity' => $args->getObject()]);
	}

	/**
	 * @return void
	 */
	public function postRemove(Event\PostRemoveEventArgs $args)
	{
		$this->logger->info('Entity has been removed.', ['entity' => $args->getObject()]);
	}

	/**
	 * @return void
	 */
	public function postUpdate(Event\PostUpdateEventArgs $args)
	{
		$this->logger->info('Entity has been updated.', ['entity' => $args->getObject()]);
	}
 }

<?php

namespace Inkwell;

use Doctrine\Common;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Hiraeth\Dbal\FileType;
use SplFileInfo;

/**
 *
 */
class UploadSubscriber implements Common\EventSubscriber
{
	/**
	 *
	 */
	public function __construct(UploaderService $uploader)
	{
		$this->uploader = $uploader;
	}


	/**
	 *
	 */
	public function getSubscribedEvents()
	{
		return [
			Events::preUpdate,
			Events::prePersist
		];
	}


	/**
	 *
	 */
	public function prePersist(LifecycleEventArgs $args)
	{
		$this->commit($args);
	}


	/**
	 *
	 */
	public function preUpdate(LifecycleEventArgs $args)
	{
		$this->commit($args);
	}


	/**
	 *
	 */
	protected function commit(LifecycleEventArgs $args)
	{
		$manager = $args->getEntityManager();
		$entity = $args->getEntity();

		$meta_data = $manager->getClassMetaData(get_class($entity));

		foreach ($meta_data->getFieldNames() as $field) {
			$mapping = $meta_data->getFieldMapping($field);

			if ($mapping['type'] != FileType::FILE) {
				continue;
			}

			if (!$value = $meta_data->getFieldValue($entity, $field)) {
				continue;
			}

			$meta_data->setFieldValue($entity, $field, $this->uploader->commit($value));
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCA\BetterSharing\Db\Bundle;
use OCA\BetterSharing\Db\Recipient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;

/** Tells the sharer, through a Nextcloud notification, about every recipient that failed. */
class FailureNotifier {
	public function __construct(
		private IManager $notifications,
		private ITimeFactory $time,
	) {
	}

	/** @param Recipient[] $rows */
	public function notifyFailures(string $uid, Bundle $bundle, array $rows): void {
		foreach ($rows as $row) {
			$n = $this->notifications->createNotification();
			$n->setApp('bettersharing')
				->setUser($uid)
				->setDateTime($this->time->getDateTime())
				->setObject('recipient', (string)$row->getId())
				->setSubject('share_failed', [
					'name' => $bundle->getName(),
					'recipient' => $row->getDisplay(),
					'reason' => (string)$row->getResult(),
					'fileId' => $bundle->getFileId(),
				]);
			$this->notifications->notify($n);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return 'bettersharing';
	}

	public function getName(): string {
		return $this->l10nFactory->get('bettersharing')->t('BetterSharing');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'bettersharing' || $notification->getSubject() !== 'share_failed') {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get('bettersharing', $languageCode);
		$p = $notification->getSubjectParameters();
		$notification->setParsedSubject($l->t('"%1$s" was not shared with %2$s', [(string)($p['name'] ?? ''), (string)($p['recipient'] ?? '')]));
		$notification->setParsedMessage((string)($p['reason'] ?? '') . ' ' . $l->t('Open the share to try again.'));
		$notification->setLink($this->url->linkToRouteAbsolute('files.View.showFile', ['fileid' => (int)($p['fileId'] ?? 0)]));
		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/error.svg')));
		return $notification;
	}
}

<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCA\BetterSharing\Notification\Notifier;
use OCP\Notification\IManager;
use OCP\Notification\UnknownNotificationException;
use OCP\Server;

class NotifierTest extends IntegrationTestCase {
	public function testFailureNotificationTellsTheSharerWhatHappened(): void {
		$n = Server::get(IManager::class)->createNotification();
		$n->setApp('bettersharing')->setUser($this->a1->getUID())->setDateTime(new \DateTime())
			->setObject('recipient', '1')
			->setSubject('share_failed', ['name' => 'Client packet', 'recipient' => 'client@example.com', 'reason' => 'Blocked by policy', 'fileId' => 42]);
		$parsed = Server::get(Notifier::class)->prepare($n, 'en');
		$this->assertSame('"Client packet" was not shared with client@example.com', $parsed->getParsedSubject());
		$this->assertStringContainsString('Blocked by policy', $parsed->getParsedMessage());
		$this->assertStringContainsString('/f/42', $parsed->getLink());
	}

	public function testOtherNotificationsAreLeftAlone(): void {
		$n = Server::get(IManager::class)->createNotification();
		$n->setApp('files')->setSubject('something');
		$this->expectException(UnknownNotificationException::class);
		Server::get(Notifier::class)->prepare($n, 'en');
	}
}

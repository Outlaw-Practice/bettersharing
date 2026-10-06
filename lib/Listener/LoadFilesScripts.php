<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Listener;

use OCA\BetterSharing\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads BetterSharing in the Files app. It is an init script so its sidebar
 * tabs register before the stock Sharing tab, which Nextcloud then skips
 * (the first tab registered under an ID wins).
 *
 * @template-implements IEventListener<Event>
 */
class LoadFilesScripts implements IEventListener {
	public function handle(Event $event): void {
		Util::addInitScript(Application::APP_ID, 'bettersharing-init');
		Util::addStyle(Application::APP_ID, 'bettersharing-init');
	}
}

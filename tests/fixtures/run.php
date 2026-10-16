<?php

declare(strict_types=1);

// Runs one BetterSharing service call in its own PHP process, for concurrency tests.
// Usage: php run.php <bundle|preset> <uid> <json request>
define('PHPUNIT_RUN', 1);
require_once '/var/www/html/lib/base.php';
\OC_App::loadApp('bettersharing');
\OC_App::loadApp('sharebymail');
\OC_App::loadApp('files_sharing');
\OC_App::loadApp('notifications');
\OC_App::loadApp('activity');
\OC_App::loadApp('files_external');

use OCA\BetterSharing\Service\BundleService;
use OCA\BetterSharing\Service\PresetService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

[$_, $what, $uid, $json] = $argv;
$user = Server::get(IUserManager::class)->get($uid);
Server::get(IUserSession::class)->setUser($user);
\OC_Util::setupFS($uid);
$request = json_decode($json, true);
try {
	$result = match ($what) {
		'bundle' => Server::get(BundleService::class)->create($user, $request),
		'update' => Server::get(BundleService::class)->update($user, (int)$request['bundleId'], $request['request']),
		'download' => ['id' => 0, 'allowed' => Server::get(\OCA\BetterSharing\Service\PermissionPolicy::class)->downloadAllowed(
			$uid, Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get(ltrim((string)$request['path'], '/')))],
		default => Server::get(PresetService::class)->create($user, $request),
	};
	echo json_encode(['ok' => true, 'id' => is_array($result) ? $result['id'] : $result->getId(), 'result' => $result]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(),
        'error_class' => get_class($e), 'source_line' => $e->getLine()]);
}

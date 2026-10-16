<?php

declare(strict_types=1);

// Boots the real Nextcloud in the dev container. These tests create users,
// groups, files and shares in that instance and remove them afterwards.
define('PHPUNIT_RUN', 1);
require_once '/var/www/html/lib/base.php';
require_once __DIR__ . '/../vendor/autoload.php';
\OC_App::loadApp('bettersharing');
\OC_App::loadApp('sharebymail');
\OC_App::loadApp('files_sharing');
\OC_App::loadApp('notifications');
\OC_App::loadApp('activity');
\OC_App::loadApp('files_external');

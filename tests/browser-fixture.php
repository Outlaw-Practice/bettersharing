<?php
declare(strict_types=1);
// Disposable local browser identities only. Password is delivered in a private file.
define('OC_CONSOLE', 1);
require '/opt/nextcloud/current/lib/base.php';
OC_App::loadApps();
$stateFile = '/tmp/bettersharing-browser-state.json';
$users = OCP\Server::get(OCP\IUserManager::class);
$groups = OCP\Server::get(OCP\IGroupManager::class);
$config = OCP\Server::get(OCP\IConfig::class);
$appConfig = OCP\Server::get(OCP\IAppConfig::class);
try {
    if (($argv[1] ?? '') === 'setup') {
        if (file_exists($stateFile)) { throw new RuntimeException('Fixture already active'); }
        foreach (['alice', 'bob', 'carol'] as $uid) {
            if ($users->userExists($uid)) { throw new RuntimeException('Fixture identity exists'); }
        }
        foreach (['firm-a', 'firm-a-paralegals', 'firm-b'] as $gid) {
            if ($groups->groupExists($gid)) { throw new RuntimeException('Fixture group exists'); }
        }
        $state = ['users' => [], 'groups' => [],
            'firm_groups' => $appConfig->getValueString('bettersharing', 'firm_groups', '[]'),
            'twofactor_enforced' => $config->getSystemValue('twofactor_enforced', null)];
        file_put_contents($stateFile, json_encode($state));
        chmod($stateFile, 0600);
        $save = static function () use (&$state, $stateFile): void {
            file_put_contents($stateFile, json_encode($state));
        };
        foreach (['firm-a', 'firm-a-paralegals', 'firm-b'] as $gid) {
            if (!$groups->createGroup($gid)) { throw new RuntimeException('Group creation failed'); }
            $state['groups'][] = $gid;
            $save();
        }
        $password = trim(file_get_contents('/tmp/bettersharing-browser-password'));
        foreach (['alice' => ['Alice Ames', 'firm-a'], 'bob' => ['Bob Barnes', 'firm-a'],
                   'carol' => ['Carol Cole', 'firm-b']] as $uid => [$name, $gid]) {
            $user = $users->createUser($uid, $password);
            if (!$user) { throw new RuntimeException('User creation failed'); }
            $state['users'][] = $uid;
            $save();
            $user->setDisplayName($name);
            $user->setSystemEMailAddress($uid . '@firm.test');
            // Onboarding is covered by the platform's browser tests. Mark it
            // seen for these disposable users before exercising sharing clicks.
            if (class_exists(OCA\FirstRunWizard\Constants::class)) {
                OCP\Server::get(OCP\Config\IUserConfig::class)->setValueString(
                    $uid, 'firstrunwizard', 'show', OCA\FirstRunWizard\Constants::CHANGELOG_VERSION);
            }
            $groups->get($gid)->addUser($user);
            OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($uid);
        }
        $groups->get('firm-a-paralegals')->addUser($users->get('alice'));
        OCP\Server::get(OCP\Group\ISubAdmin::class)->createSubAdmin($users->get('alice'), $groups->get('firm-a'));
        $appConfig->setValueString('bettersharing', 'firm_groups', '["firm-a","firm-b"]');
        $config->setSystemValue('twofactor_enforced', false);
        echo json_encode(['status' => 'ready', 'users_created' => 3]);
    } elseif (($argv[1] ?? '') === 'cleanup') {
        $state = json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
        $system = OCP\Server::get(OC\SystemConfig::class);
        $native = (new ReflectionProperty(OC\SystemConfig::class, 'config'))->getValue($system);
        $delete = Closure::bind(function ($user): bool {
            $had = array_key_exists('files.trash.delete', $this->envCache);
            $old = $this->envCache['files.trash.delete'] ?? null;
            $this->envCache['files.trash.delete'] = true;
            try { return $user->delete(); }
            finally {
                if ($had) { $this->envCache['files.trash.delete'] = $old; }
                else { unset($this->envCache['files.trash.delete']); }
            }
        }, $native, OC\Config::class);
        $db = OCP\Server::get(OCP\IDBConnection::class);
        foreach ($state['users'] as $uid) {
            if (!in_array($uid, ['alice', 'bob', 'carol'], true)) { throw new RuntimeException('Unexpected fixture user'); }
            $query = $db->getQueryBuilder();
            $ids = $query->select('id')->from('bettersharing_bundles')
                ->where($query->expr()->eq('owner', $query->createNamedParameter($uid)))
                ->executeQuery()->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $query = $db->getQueryBuilder();
                $query->delete('bettersharing_recipients')
                    ->where($query->expr()->eq('bundle_id', $query->createNamedParameter((int)$id)))->executeStatement();
            }
            $query = $db->getQueryBuilder();
            $query->delete('bettersharing_bundles')
                ->where($query->expr()->eq('owner', $query->createNamedParameter($uid)))->executeStatement();
        }
        foreach (['firm-a', 'firm-b'] as $gid) {
            if (!in_array($gid, $state['groups'], true)) { continue; }
            $query = $db->getQueryBuilder();
            $query->delete('bettersharing_presets')
                ->where($query->expr()->eq('firm', $query->createNamedParameter($gid)))->executeStatement();
        }
        foreach ($state['users'] as $uid) {
            if (!in_array($uid, ['alice', 'bob', 'carol'], true)) { throw new RuntimeException('Unexpected fixture user'); }
            $user = $users->get($uid);
            if ($user && !$delete($user)) { throw new RuntimeException('User cleanup failed'); }
        }
        foreach ($state['groups'] as $gid) {
            if (!in_array($gid, ['firm-a', 'firm-a-paralegals', 'firm-b'], true)) { throw new RuntimeException('Unexpected fixture group'); }
            $group = $groups->get($gid);
            if ($group && !$group->delete()) { throw new RuntimeException('Group cleanup failed'); }
        }
        $appConfig->setValueString('bettersharing', 'firm_groups', $state['firm_groups']);
        if ($state['twofactor_enforced'] === null) { $config->deleteSystemValue('twofactor_enforced'); }
        else { $config->setSystemValue('twofactor_enforced', $state['twofactor_enforced']); }
        unlink($stateFile);
        @unlink('/tmp/bettersharing-browser-password');
        echo json_encode(['status' => 'cleaned', 'users_removed' => count($state['users'])]);
    } else { throw new RuntimeException('Unknown fixture operation'); }
} catch (Throwable $error) {
    @unlink('/tmp/bettersharing-browser-password');
    echo json_encode(['status' => 'failed', 'error_class' => get_class($error), 'source_line' => $error->getLine()]);
    exit(1);
}

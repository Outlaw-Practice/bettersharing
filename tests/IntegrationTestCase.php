<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests against the real dev Nextcloud. Each test gets two fresh firms:
 * firm A (owner "a1", who administers it, and "a2") and firm B ("b1").
 * Everything created is removed in tearDown.
 */
abstract class IntegrationTestCase extends TestCase {
	protected string $suffix;
	protected string $firmA;
	protected string $firmB;
	protected IUser $a1;
	protected IUser $a2;
	protected IUser $b1;
	/** @var string[] */
	private array $users = [];
	/** @var string[] */
	private array $groups = [];
	private string $savedFirms = '[]';
	private array $savedSystem = [];

	protected function setUp(): void {
		parent::setUp();
		$this->suffix = bin2hex(random_bytes(4));
		$this->firmA = 'ta-' . $this->suffix;
		$this->firmB = 'tb-' . $this->suffix;
		$this->group($this->firmA);
		$this->group($this->firmB);
		$this->a1 = $this->user('a1', [$this->firmA]);
		$this->a2 = $this->user('a2', [$this->firmA]);
		$this->b1 = $this->user('b1', [$this->firmB]);
		$appConfig = Server::get(IAppConfig::class);
		$this->savedFirms = $appConfig->getValueString('bettersharing', 'firm_groups', '[]');
		$appConfig->setValueString('bettersharing', 'firm_groups', json_encode([$this->firmA, $this->firmB]));
		$group = Server::get(IGroupManager::class)->get($this->firmA);
		Server::get(\OCP\Group\ISubAdmin::class)->createSubAdmin($this->a1, $group);
		$this->actAs($this->a1);
		$this->clearMail();
	}

	protected function tearDown(): void {
		foreach ($this->savedSystem as $key => $value) {
			if ($value === null) {
				Server::get(IConfig::class)->deleteSystemValue($key);
			} else {
				Server::get(IConfig::class)->setSystemValue($key, $value);
			}
		}
		$db = Server::get(IDBConnection::class);
		foreach ($this->users as $uid) {
			$qb = $db->getQueryBuilder();
			$qb->select('id')->from('bettersharing_bundles')->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));
			$ids = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
			foreach ($ids as $id) {
				$d = $db->getQueryBuilder();
				$d->delete('bettersharing_recipients')->where($d->expr()->eq('bundle_id', $d->createNamedParameter((int)$id)))->executeStatement();
			}
			$d = $db->getQueryBuilder();
			$d->delete('bettersharing_bundles')->where($d->expr()->eq('owner', $d->createNamedParameter($uid)))->executeStatement();
		}
		foreach ([$this->firmA, $this->firmB] as $firm) {
			$d = $db->getQueryBuilder();
			$d->delete('bettersharing_presets')->where($d->expr()->eq('firm', $d->createNamedParameter($firm)))->executeStatement();
		}
		Server::get(IUserSession::class)->setUser(null);
		foreach ($this->users as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		foreach ($this->groups as $gid) {
			Server::get(IGroupManager::class)->get($gid)?->delete();
		}
		Server::get(IAppConfig::class)->setValueString('bettersharing', 'firm_groups', $this->savedFirms);
		parent::tearDown();
	}

	protected function user(string $name, array $groups): IUser {
		$uid = $name . '-' . $this->suffix;
		$user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16)));
		$user->setDisplayName(ucfirst($name) . ' ' . $this->suffix);
		$user->setSystemEMailAddress($uid . '@firm.test');
		foreach ($groups as $gid) {
			Server::get(IGroupManager::class)->get($gid)->addUser($user);
		}
		$this->users[] = $uid;
		Server::get(IRootFolder::class)->getUserFolder($uid); // as on first login
		return $user;
	}

	protected function group(string $gid): void {
		Server::get(IGroupManager::class)->createGroup($gid);
		$this->groups[] = $gid;
	}

	protected function addToGroup(IUser $user, string $gid): void {
		Server::get(IGroupManager::class)->get($gid)->addUser($user);
	}

	protected function actAs(IUser $user): void {
		Server::get(IUserSession::class)->setUser($user);
	}

	protected function folder(IUser $user, string $name): Folder {
		$folder = Server::get(IRootFolder::class)->getUserFolder($user->getUID())->newFolder($name . '-' . $this->suffix);
		$folder->newFile('inside.txt', 'test content');
		return $folder;
	}

	/** Temporarily change a system config value; restored in tearDown. */
	protected function setSystem(string $key, mixed $value): void {
		$config = Server::get(IConfig::class);
		if (!array_key_exists($key, $this->savedSystem)) {
			$this->savedSystem[$key] = $config->getSystemValue($key, null);
		}
		$config->setSystemValue($key, $value);
	}

	protected function clearMail(): void {
		$this->mailpit('DELETE', '/api/v1/messages');
	}

	/** @return list<array{to: string, subject: string, text: string}> */
	protected function mail(): array {
		$list = json_decode($this->mailpit('GET', '/api/v1/messages?limit=200'), true)['messages'] ?? [];
		$out = [];
		foreach ($list as $m) {
			$full = json_decode($this->mailpit('GET', '/api/v1/message/' . $m['ID']), true);
			$out[] = [
				'to' => strtolower($m['To'][0]['Address'] ?? ''),
				'subject' => (string)$m['Subject'],
				'text' => (string)($full['Text'] ?? ''),
			];
		}
		return $out;
	}

	protected function mailTo(string $address): array {
		return array_values(array_filter($this->mail(), fn ($m) => $m['to'] === strtolower($address)));
	}

	private function mailpit(string $method, string $path): string {
		$ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10]]);
		return (string)@file_get_contents('http://mail:8025' . $path, false, $ctx);
	}

	/** Shares owned by a user on a node, straight from Nextcloud. */
	protected function sharesOn(IUser $owner, \OCP\Files\Node $node, int $type): array {
		return Server::get(\OCP\Share\IManager::class)->getSharesBy($owner->getUID(), $type, $node, true, -1, 0);
	}
}

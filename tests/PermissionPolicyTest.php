<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCA\BetterSharing\Db\Recipient;
use OCA\BetterSharing\Service\FirmException;
use OCA\BetterSharing\Service\OutsideSharingReadiness;
use OCA\BetterSharing\Service\PermissionPolicy;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

class PermissionPolicyTest extends IntegrationTestCase {
	private const R = Constants::PERMISSION_READ;
	private const C = Constants::PERMISSION_CREATE;
	private const U = Constants::PERMISSION_UPDATE;
	private const D = Constants::PERMISSION_DELETE;
	private const S = Constants::PERMISSION_SHARE;

	private function policy(): PermissionPolicy {
		return Server::get(PermissionPolicy::class);
	}

	public function testResharingIsNeverGranted(): void {
		$this->assertSame(self::R, $this->policy()->validateRequested(self::R | self::S));
	}

	public function testEditAndDeleteIncludeView(): void {
		$this->assertSame(self::R | self::U, $this->policy()->validateRequested(self::U));
		$this->assertSame(self::R | self::D, $this->policy()->validateRequested(self::D));
	}

	public function testNothingOrUnknownBitsAreRefused(): void {
		$this->expectException(FirmException::class);
		$this->policy()->validateRequested(0);
	}

	public function testFolderMappingPerRecipientKind(): void {
		$folder = $this->folder($this->a1, 'Docs');
		$p = $this->policy();
		// Upload only: outside recipients get exactly CREATE; firm recipients always also get view.
		$this->assertSame(self::C, $p->forRecipient(self::C, Recipient::KIND_EMAIL, $folder));
		$this->assertSame(self::R | self::C, $p->forRecipient(self::C, Recipient::KIND_USER, $folder));
		$this->assertSame(self::R | self::C, $p->forRecipient(self::C, Recipient::KIND_GROUP, $folder));
		$this->assertSame(self::R | self::C | self::U | self::D, $p->forRecipient(self::R | self::C | self::U | self::D, Recipient::KIND_EMAIL, $folder));
	}

	public function testFileDropsCreateAndDelete(): void {
		$file = $this->folder($this->a1, 'Docs')->get('inside.txt');
		$this->assertSame(self::R | self::U, $this->policy()->forRecipient(self::R | self::C | self::U | self::D, Recipient::KIND_USER, $file));
	}

	public function testUploadOnlyOnAFileIsRefused(): void {
		$file = $this->folder($this->a1, 'Docs')->get('inside.txt');
		$this->expectException(FirmException::class);
		$this->policy()->forRecipient(self::C, Recipient::KIND_EMAIL, $file);
	}

	public function testPasswordMailRuleMatchesSharebymail(): void {
		$this->assertTrue(OutsideSharingReadiness::passwordMailEnabled('yes'));
		$this->assertFalse(OutsideSharingReadiness::passwordMailEnabled('1'));
		$this->assertFalse(OutsideSharingReadiness::passwordMailEnabled('no'));
		$this->assertFalse(OutsideSharingReadiness::passwordMailEnabled(''));
	}

	public function testOwnItemsAllowDownload(): void {
		$folder = $this->folder($this->a1, 'Mine');
		$this->assertTrue($this->policy()->downloadAllowed($this->a1->getUID(), $folder));
	}

	public function testNoDownloadParentShareIsRefusedForItemAndChildren(): void {
		// a2 shares a folder with a1 with SHARE but without download.
		$this->actAs($this->a2);
		$folder = $this->folder($this->a2, 'Restricted');
		$folder->newFolder('Sub');
		$manager = Server::get(IManager::class);
		$share = $manager->newShare();
		$share->setNode($folder);
		$share->setShareType(IShare::TYPE_USER);
		$share->setSharedWith($this->a1->getUID());
		$share->setSharedBy($this->a2->getUID());
		$share->setPermissions(self::R | self::S);
		$attributes = $share->newAttributes();
		$attributes->setAttribute('permissions', 'download', false);
		$share->setAttributes($attributes);
		$manager->createShare($share);

		$this->actAs($this->a1);
		$a1Folder = Server::get(IRootFolder::class)->getUserFolder($this->a1->getUID());
		$mounted = $a1Folder->get('Restricted-' . $this->suffix);
		$this->assertFalse($this->policy()->downloadAllowed($this->a1->getUID(), $mounted));
		$this->assertFalse($this->policy()->downloadAllowed($this->a1->getUID(), $mounted->get('Sub')));
		$this->assertFalse($this->policy()->downloadAllowed($this->a1->getUID(), $mounted->get('inside.txt')));
	}

	public function testConservativeEditRule(): void {
		$share = Server::get(IManager::class)->newShare();
		$share->setShareOwner('owner');
		$share->setSharedBy('creator');
		$this->assertTrue(PermissionPolicy::canEdit('owner', $share));
		$this->assertTrue(PermissionPolicy::canEdit('creator', $share));
		$this->assertFalse(PermissionPolicy::canEdit('someone-else', $share));
	}

	/** An admin-configured external storage is an ownerless mount (no file owner). */
	public function testOwnerlessMountUsesTheSameConservativeRule(): void {
		$dir = '/tmp/bs-ext-' . $this->suffix;
		mkdir($dir . '/Case', 0770, true);
		file_put_contents($dir . '/Case/inside.txt', 'test');
		$occ = fn (string ...$args) => trim((string)shell_exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1'));
		$occ('app:enable', 'files_external');
		preg_match('/id (\\d+)/', $occ('files_external:create', 'Ext-' . $this->suffix, 'local', 'null::null', '-c', 'datadir=' . $dir), $m);
		$mountId = (int)($m[1] ?? 0);
		$this->assertGreaterThan(0, $mountId);
		try {
			$occ('files_external:option', (string)$mountId, 'enable_sharing', 'true');
			$occ('files_external:applicable', (string)$mountId, '--add-user=' . $this->a2->getUID());
			$a2Case = Server::get(IRootFolder::class)->getUserFolder($this->a2->getUID())->get('Ext-' . $this->suffix . '/Case');
			$this->assertInstanceOf(\OCP\Files\Mount\IShareOwnerlessMount::class, $a2Case->getMountPoint(), 'an ownerless mount');
			$this->assertTrue($this->downloadElsewhere($this->a2->getUID(), 'Ext-' . $this->suffix . '/Case'), 'nothing restricts the mount itself');

			$this->actAs($this->a2);
			$manager = Server::get(IManager::class);
			$share = $manager->newShare();
			$share->setNode($a2Case);
			$share->setShareType(IShare::TYPE_USER);
			$share->setSharedWith($this->a1->getUID());
			$share->setSharedBy($this->a2->getUID());
			$share->setPermissions(self::R | self::S);
			$attributes = $share->newAttributes();
			$attributes->setAttribute('permissions', 'download', false);
			$share->setAttributes($attributes);
			$created = $manager->createShare($share);
			$this->actAs($this->a1);
			$this->assertFalse($this->downloadElsewhere($this->a1->getUID(), (string)$created->getTarget()), 'the received copy is refused');
			$manager->deleteShare($created);
		} finally {
			$occ('files_external:delete', '-y', (string)$mountId);
			shell_exec('rm -rf ' . escapeshellarg($dir));
		}
	}

	/** Runs the rule in a fresh process, which sees mounts as a new request would. */
	private function downloadElsewhere(string $uid, string $path): bool {
		$proc = proc_open(['php', __DIR__ . '/fixtures/run.php', 'download', $uid, json_encode(['path' => $path])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = json_decode(stream_get_contents($pipes[1]), true);
		proc_close($proc);
		$this->assertTrue($out['ok'] ?? false, json_encode($out));
		return $out['result']['allowed'];
	}

	public function testAnItemTheUserCannotSeeIsRefused(): void {
		$theirs = $this->folder($this->a2, 'NotMine');
		$this->assertFalse($this->policy()->downloadAllowed($this->a1->getUID(), $theirs));
	}
}

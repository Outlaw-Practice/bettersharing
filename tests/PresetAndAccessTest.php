<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCA\BetterSharing\Db\PresetMapper;
use OCA\BetterSharing\Service\AccessService;
use OCA\BetterSharing\Service\BundleService;
use OCA\BetterSharing\Service\FirmException;
use OCA\BetterSharing\Service\PresetService;
use OCP\Constants;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

class PresetAndAccessTest extends IntegrationTestCase {
	private function presets(): PresetService {
		return Server::get(PresetService::class);
	}

	private function data(string $name, bool $pinned = false): array {
		return ['name' => $name, 'permissions' => Constants::PERMISSION_READ, 'expireDays' => 14, 'message' => '', 'pinned' => $pinned];
	}

	public function testFirmsGetDefaultPresetsAndOnlySeeTheirOwn(): void {
		$a = array_map(fn ($p) => $p->getName(), $this->presets()->list($this->a1));
		$this->assertEqualsCanonicalizing(['View and download', 'Uploads only', 'Full edit'], $a);
		$this->presets()->create($this->a1, $this->data('CSM Packet'));
		$b = array_map(fn ($p) => $p->getName(), $this->presets()->list($this->b1));
		$this->assertNotContains('CSM Packet', $b);
	}

	public function testOnlyFirmAdministratorsChangePresets(): void {
		$this->expectException(FirmException::class);
		$this->presets()->create($this->a2, $this->data('Not allowed'));
	}

	public function testAnotherFirmsPresetCannotBeChanged(): void {
		$this->presets()->list($this->b1);
		$theirs = Server::get(PresetMapper::class)->findAllForFirm($this->firmB)[0];
		$this->expectException(FirmException::class);
		$this->presets()->update($this->a1, $theirs->getId(), $this->data('Hijacked'));
	}

	public function testNamesAreUniqueWithinAFirm(): void {
		$this->presets()->create($this->a1, $this->data('Packet'));
		$this->expectException(FirmException::class);
		$this->presets()->create($this->a1, $this->data('packet'));
	}

	public function testPinLimit(): void {
		$this->presets()->list($this->a1); // two defaults are pinned
		$this->presets()->create($this->a1, $this->data('Third', true));
		$this->presets()->create($this->a1, $this->data('Fourth', true));
		$this->expectException(FirmException::class);
		$this->presets()->create($this->a1, $this->data('Fifth', true));
	}

	public function testConcurrentPinsNeverExceedTheLimit(): void {
		$this->presets()->list($this->a1);
		$this->presets()->create($this->a1, $this->data('Third', true));
		$procs = [];
		$pipes = [];
		foreach (['Race one', 'Race two', 'Race three'] as $i => $name) {
			$procs[$i] = proc_open(['php', __DIR__ . '/fixtures/run.php', 'preset', $this->a1->getUID(), json_encode($this->data($name, true))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
		}
		$ok = 0;
		foreach ($procs as $i => $p) {
			$r = json_decode(stream_get_contents($pipes[$i][1]), true);
			proc_close($p);
			$ok += ($r['ok'] ?? false) ? 1 : 0;
		}
		$this->assertSame(1, $ok, 'only one of the racing pins fits');
		$this->assertSame(PresetService::PIN_MAX, Server::get(PresetMapper::class)->countPinned($this->firmA));
	}

	public function testLastPresetCannotBeDeleted(): void {
		$all = $this->presets()->list($this->a1);
		$this->presets()->delete($this->a1, $all[0]->getId());
		$this->presets()->delete($this->a1, $all[1]->getId());
		$this->expectException(FirmException::class);
		$this->presets()->delete($this->a1, $all[2]->getId());
	}

	public function testOwnerSeesEveryShareRecipientsOnlyTheirOwn(): void {
		$folder = $this->folder($this->a1, 'Who');
		Server::get(BundleService::class)->create($this->a1, [
			'fileId' => $folder->getId(),
			'recipients' => [['type' => 'user', 'id' => $this->a2->getUID()], ['type' => 'email', 'id' => 'client@example.com']],
			'permissions' => Constants::PERMISSION_READ,
			'name' => 'Who', 'message' => '', 'idempotencyKey' => 'who-' . $this->suffix,
		]);
		$owner = Server::get(AccessService::class)->list($this->a1, $folder->getId());
		$this->assertCount(2, $owner);
		$this->assertTrue($owner[0]['editable'] && $owner[1]['editable']);

		$this->actAs($this->a2);
		$recipient = Server::get(AccessService::class)->list($this->a2, $folder->getId());
		$this->assertCount(1, $recipient, 'the recipient does not see who else has access');
		$this->assertTrue($recipient[0]['sharedWithYou']);
		$this->assertFalse($recipient[0]['editable']);
	}

	public function testParentFolderSharesAreShownReadOnly(): void {
		$parent = $this->folder($this->a1, 'Parent');
		$child = $parent->newFolder('Child');
		$share = Server::get(IManager::class)->newShare();
		$share->setNode($parent);
		$share->setShareType(IShare::TYPE_USER);
		$share->setSharedWith($this->a2->getUID());
		$share->setSharedBy($this->a1->getUID());
		$share->setPermissions(Constants::PERMISSION_READ);
		Server::get(IManager::class)->createShare($share);

		$list = Server::get(AccessService::class)->list($this->a1, $child->getId());
		$this->assertCount(1, $list);
		$this->assertSame($parent->getName(), $list[0]['inheritedFrom']);
		$this->assertFalse($list[0]['editable']);
	}

	public function testSeedingIsAllOrNothing(): void {
		$failing = new class(Server::get(\OCP\IDBConnection::class)) extends PresetMapper {
			private int $inserts = 0;
			public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
				if (++$this->inserts === 2) {
					throw new \RuntimeException('crash after the first default');
				}
				return parent::insert($entity);
			}
		};
		$service = new PresetService(
			Server::get(\OCA\BetterSharing\Service\FirmService::class), $failing,
			Server::get(\OCA\BetterSharing\Service\PermissionPolicy::class), Server::get(\OCP\IDBConnection::class),
			Server::get(\OCP\AppFramework\Utility\ITimeFactory::class), Server::get(\OCP\L10N\IFactory::class)->get('bettersharing'),
		);
		try {
			$service->list($this->a1);
			$this->fail('the injected crash must surface');
		} catch (\RuntimeException) {
		}
		$this->assertSame([], Server::get(PresetMapper::class)->findAllForFirm($this->firmA), 'no partial defaults');
		$this->assertCount(3, $this->presets()->list($this->a1));
	}

	public function testAccessListNeverNamesAnotherFirm(): void {
		$config = Server::get(\OCP\IConfig::class);
		$config->setAppValue('core', 'shareapi_only_share_with_group_members', 'no');
		try {
			$folder = $this->folder($this->a1, 'Legacy');
			$spanning = 'span-' . $this->suffix;
			$this->group($spanning);
			$this->addToGroup($this->a2, $spanning);
			$this->addToGroup($this->b1, $spanning);
			$manager = Server::get(IManager::class);
			foreach ([[IShare::TYPE_USER, $this->b1->getUID()], [IShare::TYPE_GROUP, $spanning]] as [$type, $with]) {
				$share = $manager->newShare();
				$share->setNode($folder);
				$share->setShareType($type);
				$share->setSharedWith($with);
				$share->setSharedBy($this->a1->getUID());
				$share->setPermissions(Constants::PERMISSION_READ);
				$manager->createShare($share);
			}
		} finally {
			$config->deleteAppValue('core', 'shareapi_only_share_with_group_members');
		}
		$list = Server::get(AccessService::class)->list($this->a1, $folder->getId());
		$this->assertCount(2, $list);
		foreach ($list as $entry) {
			$this->assertSame('outside', $entry['kind']);
			$this->assertSame('Someone outside your firm', $entry['display']);
		}
		$json = json_encode($list);
		$this->assertStringNotContainsString($this->b1->getUID(), $json);
		$this->assertStringNotContainsString($spanning, $json);
		$this->assertStringNotContainsString('B1 ' . $this->suffix, $json);
	}
}

<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCA\BetterSharing\Db\Bundle;
use OCA\BetterSharing\Db\BundleMapper;
use OCA\BetterSharing\Db\PresetMapper;
use OCA\BetterSharing\Db\Recipient;
use OCA\BetterSharing\Db\RecipientMapper;
use OCA\BetterSharing\Service\BundleService;
use OCA\BetterSharing\Service\FirmException;
use OCA\BetterSharing\Service\OutsideSharingReadiness;
use OCA\BetterSharing\Service\PresetService;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

class BundleServiceTest extends IntegrationTestCase {
	private const CLIENT = 'client@example.com';

	private function service(): BundleService {
		return Server::get(BundleService::class);
	}

	private function request(Folder $folder, array $recipients, array $extra = []): array {
		return array_merge([
			'fileId' => $folder->getId(),
			'recipients' => $recipients,
			'presetId' => null,
			'permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_CREATE,
			'expireDate' => null,
			'name' => 'Client packet',
			'message' => 'Sign the pages and upload them here.',
			'idempotencyKey' => 'key-' . bin2hex(random_bytes(8)),
		], $extra);
	}

	private function a2(): array {
		return ['type' => 'user', 'id' => $this->a2->getUID()];
	}

	private function client(): array {
		return ['type' => 'email', 'id' => self::CLIENT];
	}

	private function row(array $bundle, string $recipient): array {
		foreach ($bundle['recipients'] as $r) {
			if ($r['recipient'] === $recipient) {
				return $r;
			}
		}
		$this->fail('No row for ' . $recipient);
	}

	private function shareFor(array $row): ?IShare {
		$id = $this->rowEntity($row['id'])->getShareId();
		if ($id === null) {
			return null;
		}
		try {
			return Server::get(IManager::class)->getShareById($id);
		} catch (\OCP\Share\Exceptions\ShareNotFound) {
			return null;
		}
	}

	private function rowEntity(int $id): Recipient {
		return Server::get(RecipientMapper::class)->get($id);
	}

	private function presetId(string $name): int {
		foreach (Server::get(PresetService::class)->list($this->a1) as $p) {
			if ($p->getName() === $name) {
				return $p->getId();
			}
		}
		$this->fail('No preset ' . $name);
	}

	private function notificationsFor(string $uid): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('bettersharing')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testMixedRecipientsAreSharedAndMailed(): void {
		$folder = $this->folder($this->a1, 'Packet');
		$presetId = $this->presetId('View and download');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2(), $this->client()], ['presetId' => $presetId]));

		$this->assertSame(Bundle::COMPLETE, $bundle['status']);
		$user = $this->shareFor($this->row($bundle, $this->a2->getUID()));
		$email = $this->shareFor($this->row($bundle, self::CLIENT));
		$this->assertSame(Recipient::SHARED, $this->row($bundle, $this->a2->getUID())['status']);
		$this->assertSame(IShare::TYPE_USER, $user->getShareType());
		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE, $user->getPermissions());
		$this->assertNotFalse($user->getAttributes()?->getAttribute('permissions', 'download'));
		$this->assertSame(IShare::TYPE_EMAIL, $email->getShareType());
		$this->assertNotEmpty($email->getPassword());
		$this->assertSame('Client packet', $email->getLabel());
		$this->assertSame('Sign the pages and upload them here.', $email->getNote());
		$this->assertSame(0, $email->getPermissions() & Constants::PERMISSION_SHARE);

		$mail = $this->mailTo(self::CLIENT);
		$this->assertCount(2, $mail, 'one email with the link, one with the password');
		$this->assertTrue((bool)array_filter($mail, fn ($m) => str_contains($m['text'], 'Sign the pages')));

		$this->assertSame(1, Server::get(PresetMapper::class)->findForFirm($presetId, $this->firmA)->getUseCount());
	}

	public function testExistingAccessIsReportedAndNeverTouched(): void {
		$folder = $this->folder($this->a1, 'Shared');
		$manager = Server::get(IManager::class);
		$share = $manager->newShare();
		$share->setNode($folder);
		$share->setShareType(IShare::TYPE_USER);
		$share->setSharedWith($this->a2->getUID());
		$share->setSharedBy($this->a1->getUID());
		$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$existing = $manager->createShare($share);

		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2(), $this->client()]));
		$row = $this->row($bundle, $this->a2->getUID());
		$this->assertSame(Recipient::EXISTING, $row['status']);
		$this->assertSame($existing->getFullId(), $this->rowEntity($row['id'])->getExistingShareId());
		$this->assertNull($this->rowEntity($row['id'])->getShareId(), 'pre-existing access is never owned');

		$this->service()->stopSharing($this->a1, $bundle['id'], $this->versions($bundle));
		$still = $manager->getShareById($existing->getFullId());
		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, $still->getPermissions());
		$this->assertNull($this->shareFor($this->row($bundle, self::CLIENT)));
	}

	public function testFailedRecipientKeepsOthersAndNotifiesSharer(): void {
		$folder = $this->folder($this->a1, 'Partial');
		// Nextcloud refuses the client's share (another app blocks it); the firm share must still happen.
		$block = true;
		$dispatcher = Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
		$dispatcher->addListener(\OCP\Share\Events\BeforeShareCreatedEvent::class, function ($event) use (&$block) {
			if ($block && $event->getShare()->getSharedWith() === self::CLIENT) {
				$event->setError('Blocked by policy');
				$event->stopPropagation();
			}
		});
		try {
			$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2(), $this->client()]));
		} finally {
			$block = false;
		}

		$this->assertSame(Recipient::SHARED, $this->row($bundle, $this->a2->getUID())['status']);
		$client = $this->row($bundle, self::CLIENT);
		$this->assertSame(Recipient::FAILED, $client['status']);
		$this->assertNotEmpty($client['result']);
		$this->assertCount(0, $this->sharesOn($this->a1, $folder, IShare::TYPE_EMAIL));
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
		$block = false;
		$this->assertSame(1, $this->notificationsFor($this->a1->getUID()));

		$retried = $this->service()->retry($this->a1, $bundle['id']);
		$this->assertSame(Recipient::SHARED, $this->row($retried, self::CLIENT)['status']);
		$this->assertSame(Bundle::COMPLETE, $retried['status']);
	}

	public function testSameKeySameRequestReturnsRecordedResult(): void {
		$folder = $this->folder($this->a1, 'Twice');
		$req = $this->request($folder, [$this->a2()]);
		$first = $this->service()->create($this->a1, $req);
		$second = $this->service()->create($this->a1, $req);
		$this->assertSame($first['id'], $second['id']);
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
	}

	public function testSameKeyDifferentRequestIsRefused(): void {
		$folder = $this->folder($this->a1, 'Changed');
		$req = $this->request($folder, [$this->a2()]);
		$this->service()->create($this->a1, $req);
		$this->expectException(FirmException::class);
		$this->service()->create($this->a1, array_merge($req, ['message' => 'different']));
	}

	public function testConcurrentSubmissionsWithTheSameKeyShareOnce(): void {
		$folder = $this->folder($this->a1, 'Race');
		$json = json_encode($this->request($folder, [$this->a2(), $this->client()]));
		$procs = [];
		for ($i = 0; $i < 2; $i++) {
			$procs[] = proc_open(['php', __DIR__ . '/fixtures/run.php', 'bundle', $this->a1->getUID(), $json], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
		}
		$results = [];
		foreach ($procs as $i => $p) {
			$results[] = json_decode(stream_get_contents($pipes[$i][1]), true);
			proc_close($p);
		}
		$this->assertTrue($results[0]['ok'] ?? false, json_encode($results));
		$this->assertTrue($results[1]['ok'] ?? false, json_encode($results));
		$this->assertSame($results[0]['id'], $results[1]['id']);
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_EMAIL));
	}

	public function testInterruptedAfterShareBeforeIdRecordedIsAdopted(): void {
		$folder = $this->folder($this->a1, 'Adopt');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		$row = $this->rowEntity($this->row($bundle, $this->a2->getUID())['id']);
		$shareId = $row->getShareId();
		// The process stopped after Nextcloud created the share, before its ID was saved.
		$row->setStatus(Recipient::CLAIMED);
		$row->setShareId(null);
		$row->setClaimedAt(time() - BundleService::STALE_CLAIM_SECONDS - 60);
		Server::get(RecipientMapper::class)->update($row);
		$this->markInProgress($bundle['id']);

		$recovered = $this->service()->get($this->a1, $bundle['id']);
		$this->assertSame(Recipient::SHARED, $this->row($recovered, $this->a2->getUID())['status']);
		$this->assertSame($shareId, $this->rowEntity($row->getId())->getShareId());
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
	}

	public function testInterruptedBeforeShareExistsIsReportedThenRetried(): void {
		$folder = $this->folder($this->a1, 'Lost');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		$row = $this->rowEntity($this->row($bundle, $this->a2->getUID())['id']);
		Server::get(IManager::class)->deleteShare(Server::get(IManager::class)->getShareById((string)$row->getShareId()));
		$row->setStatus(Recipient::CLAIMED);
		$row->setShareId(null);
		$row->setClaimedAt(time() - BundleService::STALE_CLAIM_SECONDS - 60);
		Server::get(RecipientMapper::class)->update($row);
		$this->markInProgress($bundle['id']);

		$recovered = $this->service()->get($this->a1, $bundle['id']);
		$this->assertSame(Recipient::FAILED, $this->row($recovered, $this->a2->getUID())['status']);
		$retried = $this->service()->retry($this->a1, $bundle['id']);
		$this->assertSame(Recipient::SHARED, $this->row($retried, $this->a2->getUID())['status']);
	}

	public function testInterruptedBeforeCallIsSharedWhenResubmitted(): void {
		$folder = $this->folder($this->a1, 'Pending');
		$req = $this->request($folder, [$this->a2()]);
		$bundle = $this->service()->create($this->a1, $req);
		$row = $this->rowEntity($this->row($bundle, $this->a2->getUID())['id']);
		Server::get(IManager::class)->deleteShare(Server::get(IManager::class)->getShareById((string)$row->getShareId()));
		$row->setStatus(Recipient::PENDING);
		$row->setShareId(null);
		Server::get(RecipientMapper::class)->update($row);
		$this->markInProgress($bundle['id']);

		$resumed = $this->service()->create($this->a1, $req);
		$this->assertSame($bundle['id'], $resumed['id']);
		$this->assertSame(Recipient::SHARED, $this->row($resumed, $this->a2->getUID())['status']);
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
	}

	public function testPresetUsageCountsOncePerOperation(): void {
		$folder = $this->folder($this->a1, 'Usage');
		$presetId = $this->presetId('Uploads only');
		$req = $this->request($folder, [$this->a2(), $this->client()], ['presetId' => $presetId]);
		$bundle = $this->service()->create($this->a1, $req);
		// Interrupted after every share was recorded, before completion.
		$this->markInProgress($bundle['id']);
		$this->service()->create($this->a1, $req);
		$this->service()->create($this->a1, $req);
		$this->assertSame(2, Server::get(PresetMapper::class)->findForFirm($presetId, $this->firmA)->getUseCount(),
			'completed once originally and once on resume, never per recipient or per repeat');
	}

	public function testEditDoesNotOverwriteAShareChangedElsewhere(): void {
		$folder = $this->folder($this->a1, 'Edit');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		$row = $this->row($bundle, $this->a2->getUID());
		$share = $this->shareFor($row);
		$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_DELETE);
		Server::get(IManager::class)->updateShare($share);

		try {
			$this->service()->update($this->a1, $bundle['id'], [
				'name' => 'Renamed', 'message' => 'New note', 'permissions' => Constants::PERMISSION_READ,
				'versions' => [(string)$row['id'] => $row['version']],
			]);
			$this->fail('a stale version must refuse the save');
		} catch (FirmException $e) {
			$this->assertStringContainsString('changed this share somewhere else', $e->getMessage());
		}
		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_DELETE, $this->shareFor($row)->getPermissions());
		$this->assertSame('Client packet', $this->service()->get($this->a1, $bundle['id'])['name'], 'nothing written on conflict');
	}

	public function testEditAppliesChangesAndReportsSharesRemovedElsewhere(): void {
		$folder = $this->folder($this->a1, 'Apply');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2(), $this->client()]));
		Server::get(IManager::class)->deleteShare($this->shareFor($this->row($bundle, self::CLIENT)));
		$userRow = $this->row($bundle, $this->a2->getUID());

		$edited = $this->service()->update($this->a1, $bundle['id'], [
			'name' => 'Renamed', 'message' => 'Updated note', 'permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE,
			'versions' => [(string)$userRow['id'] => $userRow['version']],
		]);
		$this->assertSame(Recipient::REMOVED, $this->row($edited, self::CLIENT)['status']);
		$share = $this->shareFor($userRow);
		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, $share->getPermissions());
		$this->assertSame('Updated note', $share->getNote());
	}

	public function testAnotherOwnersBundleIsNotReachable(): void {
		$folder = $this->folder($this->a1, 'Private');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		$this->actAs($this->a2);
		$this->expectException(FirmException::class);
		$this->service()->get($this->a2, $bundle['id']);
	}

	public function testRecipientFromAnotherBundleIsRefused(): void {
		$one = $this->service()->create($this->a1, $this->request($this->folder($this->a1, 'One'), [$this->a2()]));
		$two = $this->service()->create($this->a1, $this->request($this->folder($this->a1, 'Two'), [$this->a2()]));
		$this->expectException(FirmException::class);
		$this->service()->removeRecipient($this->a1, $one['id'], $two['recipients'][0]['id'], (string)$two['recipients'][0]['version']);
	}

	public function testSendNewPasswordEmailsTheRecipient(): void {
		$folder = $this->folder($this->a1, 'Password');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->client()]));
		$before = $this->shareFor($this->row($bundle, self::CLIENT))->getPassword();
		$this->clearMail();
		$after = $this->service()->sendNewPassword($this->a1, $bundle['id'], $this->row($bundle, self::CLIENT)['id']);
		$this->assertSame('New password sent', $this->row($after, self::CLIENT)['result']);
		$this->assertNotSame($before, $this->shareFor($this->row($bundle, self::CLIENT))->getPassword());
		$this->assertCount(1, $this->mailTo(self::CLIENT));
	}

	public function testNoDownloadSourceIsRefusedAndLeftAlone(): void {
		$this->actAs($this->a2);
		$folder = $this->folder($this->a2, 'Locked');
		$manager = Server::get(IManager::class);
		$share = $manager->newShare();
		$share->setNode($folder);
		$share->setShareType(IShare::TYPE_USER);
		$share->setSharedWith($this->a1->getUID());
		$share->setSharedBy($this->a2->getUID());
		$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
		$attributes = $share->newAttributes();
		$attributes->setAttribute('permissions', 'download', false);
		$share->setAttributes($attributes);
		$original = $manager->createShare($share);

		$this->actAs($this->a1);
		$mounted = Server::get(IRootFolder::class)->getUserFolder($this->a1->getUID())->get('Locked-' . $this->suffix);
		try {
			$this->service()->create($this->a1, $this->request($mounted, [['type' => 'email', 'id' => 'other@example.com']]));
			$this->fail('expected a refusal');
		} catch (FirmException $e) {
			$this->assertStringContainsString('without download rights', $e->getMessage());
		}
		$this->assertFalse($manager->getShareById($original->getFullId())->getAttributes()->getAttribute('permissions', 'download'));
	}

	public function testOutsideSharingOffWhenPasswordMailDisabled(): void {
		$config = Server::get(IConfig::class);
		$config->setAppValue('sharebymail', 'sendpasswordmail', '1');
		try {
			$this->assertNotNull(Server::get(OutsideSharingReadiness::class)->problem());
			$this->expectException(FirmException::class);
			$this->service()->create($this->a1, $this->request($this->folder($this->a1, 'Off'), [$this->client()]));
		} finally {
			$config->deleteAppValue('sharebymail', 'sendpasswordmail');
		}
	}

	public function testEndDateRules(): void {
		$folder = $this->folder($this->a1, 'Dates');
		try {
			$this->service()->create($this->a1, $this->request($folder, [$this->client()], ['expireDate' => date('Y-m-d')]));
			$this->fail('today must be refused');
		} catch (FirmException $e) {
			$this->assertStringContainsString('after today', $e->getMessage());
		}
		$config = Server::get(IConfig::class);
		$config->setAppValue('core', 'shareapi_default_expire_date', 'yes');
		$config->setAppValue('core', 'shareapi_enforce_expire_date', 'yes');
		$config->setAppValue('core', 'shareapi_expire_after_n_days', '7');
		try {
			$this->expectException(FirmException::class);
			$this->service()->create($this->a1, $this->request($folder, [$this->client()], ['expireDate' => date('Y-m-d', strtotime('+30 days'))]));
		} finally {
			$config->deleteAppValue('core', 'shareapi_default_expire_date');
			$config->deleteAppValue('core', 'shareapi_enforce_expire_date');
			$config->deleteAppValue('core', 'shareapi_expire_after_n_days');
		}
	}

	public function testEmailShareGetsTheChosenEndDate(): void {
		$folder = $this->folder($this->a1, 'Ends');
		$date = date('Y-m-d', strtotime('+10 days'));
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2(), $this->client()], ['expireDate' => $date]));
		$this->assertSame($date, $this->shareFor($this->row($bundle, self::CLIENT))->getExpirationDate()?->format('Y-m-d'));
		$this->assertNull($this->shareFor($this->row($bundle, $this->a2->getUID()))->getExpirationDate(), 'firm shares do not expire');
	}

	private function runElsewhere(string $what, string $uid, array $request): array {
		$proc = proc_open(['php', __DIR__ . '/fixtures/run.php', $what, $uid, json_encode($request)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = stream_get_contents($pipes[1]);
		proc_close($proc);
		return json_decode($out, true) ?? ['ok' => false, 'error' => $out];
	}

	private function versions(array $bundle): array {
		$v = [];
		foreach ($bundle['recipients'] as $r) {
			if ($r['version'] !== null) {
				$v[(string)$r['id']] = $r['version'];
			}
		}
		return $v;
	}

	/** Blocks Nextcloud from creating shares for one recipient until the returned closure is called. */
	private function blockShareTo(string $recipient): \Closure {
		$blocked = true;
		Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCP\Share\Events\BeforeShareCreatedEvent::class, function ($event) use (&$blocked, $recipient) {
			if ($blocked && $event->getShare()->getSharedWith() === $recipient) {
				$event->setError('Blocked by policy');
				$event->stopPropagation();
			}
		});
		return function () use (&$blocked) {
			$blocked = false;
		};
	}

	private function race(array $calls): array {
		$procs = [];
		$pipes = [];
		foreach ($calls as $i => [$what, $uid, $request]) {
			$procs[$i] = proc_open(['php', __DIR__ . '/fixtures/run.php', $what, $uid, json_encode($request)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
		}
		$out = [];
		foreach ($procs as $i => $p) {
			$out[$i] = json_decode(stream_get_contents($pipes[$i][1]), true);
			proc_close($p);
		}
		return $out;
	}

	private function ownerRows(string $recipient): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		return $qb->select('r.status', 'r.share_id', 'r.existing_share_id')->from('bettersharing_recipients', 'r')
			->join('r', 'bettersharing_bundles', 'b', 'r.bundle_id = b.id')
			->where($qb->expr()->eq('r.recipient', $qb->createNamedParameter($recipient)))
			->andWhere($qb->expr()->eq('b.owner', $qb->createNamedParameter($this->a1->getUID())))
			->executeQuery()->fetchAll();
	}

	public function testDifferentKeysForTheSameRecipientNeverOwnOneShareTwice(): void {
		$folder = $this->folder($this->a1, 'TwoKeys');
		$results = $this->race([
			['bundle', $this->a1->getUID(), $this->request($folder, [$this->a2()])],
			['bundle', $this->a1->getUID(), $this->request($folder, [$this->a2()])],
		]);
		$this->assertTrue(($results[0]['ok'] ?? false) && ($results[1]['ok'] ?? false), json_encode($results));
		$this->assertNotSame($results[0]['id'], $results[1]['id']);
		$this->assertOneOwnerPerShare($folder);
	}

	public function testConcurrentEditorAddsOfTheSameRecipientOwnOneShare(): void {
		$folder = $this->folder($this->a1, 'TwoEdits');
		$one = $this->service()->create($this->a1, $this->request($folder, [$this->client()]));
		$two = $this->service()->create($this->a1, $this->request($folder, [['type' => 'email', 'id' => 'second@example.com']]));
		$add = fn (array $b) => ['update', $this->a1->getUID(), ['bundleId' => $b['id'], 'request' => [
			'name' => 'Client packet', 'message' => '', 'permissions' => 1, 'versions' => $this->versions($b), 'add' => [$this->a2()],
		]]];
		$results = $this->race([$add($one), $add($two)]);
		$this->assertTrue(($results[0]['ok'] ?? false) && ($results[1]['ok'] ?? false), json_encode($results));
		$this->assertOneOwnerPerShare($folder);
	}

	/**
	 * Concurrent requests may make Nextcloud create one share or (racing its own
	 * duplicate check) two; either way every share has exactly one owning row.
	 */
	private function assertOneOwnerPerShare(\OCP\Files\Node $folder): void {
		$core = array_map(fn (IShare $s) => $s->getFullId(), $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
		$owned = array_values(array_filter(array_map(fn ($r) => $r['share_id'], $this->ownerRows($this->a2->getUID()))));
		sort($core);
		sort($owned);
		$this->assertNotEmpty($core);
		$this->assertSame($core, $owned, 'each share owned by exactly one row, no row without a share');
	}

	public function testOwnerMovedToAnotherFirmLosesTheOldFirmsShares(): void {
		$bundle = $this->service()->create($this->a1, $this->request($this->folder($this->a1, 'Moved'), [$this->a2()]));
		Server::get(\OCP\IGroupManager::class)->get($this->firmA)->removeUser($this->a1);
		$this->addToGroup($this->a1, $this->firmB);
		foreach ([
			fn () => $this->service()->get($this->a1, $bundle['id']),
			fn () => $this->service()->removeRecipient($this->a1, $bundle['id'], $bundle['recipients'][0]['id'], (string)$bundle['recipients'][0]['version']),
			fn () => $this->service()->stopSharing($this->a1, $bundle['id'], $this->versions($bundle)),
			fn () => $this->service()->listForFile($this->a1, $bundle['fileId']),
		] as $i => $call) {
			$result = null;
			try {
				$result = $call();
			} catch (FirmException) {
				continue;
			}
			$this->assertSame([], $result, "call $i must not reach the old firm's share");
		}
		$shareId = (int)substr((string)$this->rowEntity($bundle['recipients'][0]['id'])->getShareId(), strlen('ocinternal:'));
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$this->assertSame(1, (int)$qb->select($qb->func()->count('*'))->from('share')->where($qb->expr()->eq('id', $qb->createNamedParameter($shareId)))->executeQuery()->fetchOne(), 'the old share was not touched');
	}

	public function testLostFileAccessRefusesTheBundle(): void {
		$folder = $this->folder($this->a1, 'Gone');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		$folder->delete();
		$this->expectException(FirmException::class);
		$this->service()->get($this->a1, $bundle['id']);
	}

	public function testRecipientWhoLeftTheFirmIsNotSharedOnRetry(): void {
		$folder = $this->folder($this->a1, 'Left');
		$unblock = $this->blockShareTo($this->a2->getUID());
		try {
			$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		} finally {
			$unblock();
		}
		$this->assertSame(Recipient::FAILED, $this->row($bundle, $this->a2->getUID())['status']);
		Server::get(\OCP\IGroupManager::class)->get($this->firmA)->removeUser($this->a2);
		$this->addToGroup($this->a2, $this->firmB);
		$retried = $this->service()->retry($this->a1, $bundle['id']);
		$this->assertSame(Recipient::FAILED, $this->row($retried, $this->a2->getUID())['status']);
		$this->assertCount(0, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
	}

	public function testGroupThatBecameCrossFirmIsNotSharedOnRetry(): void {
		$folder = $this->folder($this->a1, 'Grp');
		$gid = 'g-' . $this->suffix;
		$this->group($gid);
		$this->addToGroup($this->a1, $gid);
		$this->addToGroup($this->a2, $gid);
		$unblock = $this->blockShareTo($gid);
		try {
			$bundle = $this->service()->create($this->a1, $this->request($folder, [['type' => 'group', 'id' => $gid]]));
		} finally {
			$unblock();
		}
		$this->assertSame(Recipient::FAILED, $this->row($bundle, $gid)['status']);
		$this->addToGroup($this->b1, $gid);
		$retried = $this->service()->retry($this->a1, $bundle['id']);
		$this->assertSame(Recipient::FAILED, $this->row($retried, $gid)['status']);
		$this->assertCount(0, $this->sharesOn($this->a1, $folder, IShare::TYPE_GROUP));
	}

	public function testEditsWithoutVersionsAreRefusedAndWriteNothing(): void {
		$folder = $this->folder($this->a1, 'NoVersion');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		try {
			$this->service()->update($this->a1, $bundle['id'], ['name' => 'Changed', 'message' => '', 'permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE]);
			$this->fail('update without versions must be refused');
		} catch (FirmException) {
		}
		$this->assertSame('Client packet', $this->service()->get($this->a1, $bundle['id'])['name']);
		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE, $this->shareFor($this->row($bundle, $this->a2->getUID()))->getPermissions());
	}

	public function testARefusedSaveLeavesAShareRemovedElsewhereUntouched(): void {
		$folder = $this->folder($this->a1, 'Mixed');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->client(), $this->a2()]));
		$clientRow = $this->row($bundle, self::CLIENT);
		$userRow = $this->row($bundle, $this->a2->getUID());
		$clientShareId = $this->rowEntity($clientRow['id'])->getShareId();
		Server::get(IManager::class)->deleteShare($this->shareFor($clientRow)); // removed elsewhere (earlier row)
		$changed = $this->shareFor($userRow);
		$changed->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_DELETE);
		Server::get(IManager::class)->updateShare($changed); // changed elsewhere (later row)
		try {
			$this->service()->update($this->a1, $bundle['id'], [
				'name' => 'Client packet', 'message' => '', 'permissions' => Constants::PERMISSION_READ, 'versions' => $this->versions($bundle),
			]);
			$this->fail('the stale share must refuse the save');
		} catch (FirmException) {
		}
		$after = $this->rowEntity($clientRow['id']);
		$this->assertSame(Recipient::SHARED, $after->getStatus(), 'nothing written when the save is refused');
		$this->assertSame($clientShareId, $after->getShareId());
	}

	public function testRemoveAndStopNeedTheCurrentVersion(): void {
		$folder = $this->folder($this->a1, 'Stale');
		$bundle = $this->service()->create($this->a1, $this->request($folder, [$this->a2()]));
		$row = $this->row($bundle, $this->a2->getUID());
		foreach ([
			fn () => $this->service()->removeRecipient($this->a1, $bundle['id'], $row['id'], ''),
			fn () => $this->service()->removeRecipient($this->a1, $bundle['id'], $row['id'], 'stale'),
			fn () => $this->service()->stopSharing($this->a1, $bundle['id'], []),
		] as $call) {
			try {
				$call();
				$this->fail('a missing or stale version must be refused');
			} catch (FirmException) {
			}
		}
		$this->assertCount(1, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
		$this->service()->removeRecipient($this->a1, $bundle['id'], $row['id'], $row['version']);
		$this->assertCount(0, $this->sharesOn($this->a1, $folder, IShare::TYPE_USER));
	}

	public function testCompletionAndUsageCountCommitTogether(): void {
		$folder = $this->folder($this->a1, 'Atomic');
		$presetId = $this->presetId('View and download');
		$failing = new class(Server::get(IDBConnection::class)) extends PresetMapper {
			public function recordUse(int $id, int $time): void {
				throw new \RuntimeException('crash between completion and usage');
			}
		};
		$service = new BundleService(
			Server::get(\OCA\BetterSharing\Service\FirmService::class), Server::get(\OCA\BetterSharing\Service\RecipientResolver::class),
			Server::get(\OCA\BetterSharing\Service\PermissionPolicy::class), Server::get(OutsideSharingReadiness::class),
			Server::get(BundleMapper::class), Server::get(RecipientMapper::class), $failing,
			Server::get(IManager::class), Server::get(IRootFolder::class), Server::get(IDBConnection::class),
			Server::get(\OCP\AppFramework\Utility\ITimeFactory::class), Server::get(\OCP\Security\ISecureRandom::class),
			Server::get(\OCP\EventDispatcher\IEventDispatcher::class), Server::get(\OCA\BetterSharing\Service\FailureNotifier::class),
			Server::get(\OCP\L10N\IFactory::class)->get('bettersharing'), Server::get(\Psr\Log\LoggerInterface::class),
		);
		$req = $this->request($folder, [$this->a2()], ['presetId' => $presetId]);
		try {
			$service->create($this->a1, $req);
			$this->fail('the injected crash must surface');
		} catch (\RuntimeException) {
		}
		$bundleId = Server::get(BundleMapper::class)->findByKey($this->a1->getUID(), $this->firmA, $folder->getId(), $req['idempotencyKey'])->getId();
		$this->assertSame(Bundle::IN_PROGRESS, Server::get(BundleMapper::class)->findOwned($bundleId, $this->a1->getUID(), $this->firmA)->getStatus(), 'completion rolled back with the usage count');
		$this->assertSame(0, Server::get(PresetMapper::class)->findForFirm($presetId, $this->firmA)->getUseCount());
		$this->service()->create($this->a1, $req);
		$this->assertSame(1, Server::get(PresetMapper::class)->findForFirm($presetId, $this->firmA)->getUseCount());
	}

	private function markInProgress(int $bundleId): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('bettersharing_bundles')->set('status', $qb->createNamedParameter(Bundle::IN_PROGRESS))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bundleId)))->executeStatement();
	}
}

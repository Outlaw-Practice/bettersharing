<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use DateTime;
use OCA\BetterSharing\Db\Bundle;
use OCA\BetterSharing\Db\BundleMapper;
use OCA\BetterSharing\Db\PresetMapper;
use OCA\BetterSharing\Db\Recipient;
use OCA\BetterSharing\Db\RecipientMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\Events\GenerateSecurePasswordEvent;
use OCP\Security\ISecureRandom;
use OCP\Security\PasswordContext;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Creates and edits bundles. Contract (plan, Backend):
 * validate everything first; record the bundle and every recipient before any
 * share exists; then claim and share one recipient at a time. A failed
 * recipient never undoes another's share, and the sharer is told about failures.
 */
class BundleService {
	/** A claimed row older than this with no share ID was interrupted. */
	public const STALE_CLAIM_SECONDS = 120;

	private const TYPE_OF = [
		Recipient::KIND_USER => IShare::TYPE_USER,
		Recipient::KIND_GROUP => IShare::TYPE_GROUP,
		Recipient::KIND_EMAIL => IShare::TYPE_EMAIL,
	];

	public function __construct(
		private FirmService $firms,
		private RecipientResolver $resolver,
		private PermissionPolicy $policy,
		private OutsideSharingReadiness $readiness,
		private BundleMapper $bundles,
		private RecipientMapper $recipients,
		private PresetMapper $presets,
		private IManager $shareManager,
		private IRootFolder $rootFolder,
		private IDBConnection $db,
		private ITimeFactory $time,
		private ISecureRandom $random,
		private IEventDispatcher $events,
		private FailureNotifier $notifier,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{fileId?: mixed, recipients?: mixed, presetId?: mixed, permissions?: mixed,
	 *   expireDate?: mixed, name?: mixed, message?: mixed, idempotencyKey?: mixed} $req
	 * @throws FirmException
	 */
	public function create(IUser $sharer, array $req): array {
		$uid = $sharer->getUID();
		$firm = $this->firms->firmOf($sharer);
		$node = $this->shareableNode($uid, (int)($req['fileId'] ?? 0));
		$outsideAllowed = $this->readiness->problem() === null;

		$key = is_string($req['idempotencyKey'] ?? null) ? $req['idempotencyKey'] : '';
		if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $key) !== 1) {
			throw new FirmException($this->l->t('Something went wrong preparing this share. Close the dialog and try again.'));
		}

		$resolved = $this->resolver->resolve($sharer, $firm, is_array($req['recipients'] ?? null) ? $req['recipients'] : [], $outsideAllowed);
		$permissions = $this->policy->validateRequested((int)($req['permissions'] ?? 0));
		$perRecipient = [];
		foreach ($resolved as $i => $r) {
			$perRecipient[$i] = $this->policy->forRecipient($permissions, $r['kind'], $node);
		}
		if (!$this->policy->downloadAllowed($uid, $node)) {
			throw new FirmException($this->l->t('This item was shared with you without download rights, so it can\'t be shared through BetterSharing.'));
		}
		$expireDate = $this->validateExpireDate($req['expireDate'] ?? null, $resolved);
		$name = $this->validateName($req['name'] ?? '');
		$message = $this->validateMessage($req['message'] ?? '');
		$preset = null;
		if (($req['presetId'] ?? null) !== null && $req['presetId'] !== '') {
			try {
				$preset = $this->presets->findForFirm((int)$req['presetId'], $firm);
			} catch (DoesNotExistException) {
				throw new FirmException($this->l->t('That preset no longer exists. Choose another one.'));
			}
		}

		$fingerprint = hash('sha256', json_encode([
			'file' => $node->getId(),
			'recipients' => array_map(fn ($r) => $r['kind'] . ':' . $r['recipient'], $resolved),
			'permissions' => $permissions,
			'expire' => $expireDate,
			'name' => $name,
			'message' => $message,
			'preset' => $preset?->getId(),
		]));

		// A repeated submission returns the recorded result instead of sharing again.
		try {
			$existing = $this->bundles->findByKey($uid, $firm, $node->getId(), $key);
			return $this->resume($existing, $fingerprint, $node, $sharer);
		} catch (DoesNotExistException) {
		}

		$now = $this->time->getTime();
		$bundle = new Bundle();
		$bundle->setOwner($uid);
		$bundle->setFirm($firm);
		$bundle->setFileId($node->getId());
		$bundle->setName($name);
		$bundle->setMessage($message === '' ? null : $message);
		$bundle->setPresetId($preset?->getId());
		$bundle->setPresetName($preset?->getName());
		$bundle->setStatus(Bundle::IN_PROGRESS);
		$bundle->setIdemKey($key);
		$bundle->setFingerprint($fingerprint);
		$bundle->setCreatedAt($now);
		$bundle->setUpdatedAt($now);

		$this->db->beginTransaction();
		try {
			$bundle = $this->bundles->insert($bundle);
			foreach ($resolved as $i => $r) {
				$row = new Recipient();
				$row->setBundleId($bundle->getId());
				$row->setKind($r['kind']);
				$row->setRecipient($r['recipient']);
				$row->setDisplay($r['display']);
				$row->setPermissions($perRecipient[$i]);
				$row->setExpireDate($r['kind'] === Recipient::KIND_EMAIL ? $expireDate : null);
				$existingShare = $this->existingShare($node, $r['kind'], $r['recipient']);
				if ($existingShare !== null) {
					$row->setStatus(Recipient::EXISTING);
					$row->setExistingShareId($existingShare->getFullId());
					$row->setResult($this->l->t('Already has access'));
				} else {
					$row->setStatus(Recipient::PENDING);
				}
				$row->setUpdatedAt($now);
				$this->recipients->insert($row);
			}
			$this->db->commit();
		} catch (DbException $e) {
			$this->db->rollBack();
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Another request with the same key won the race; report its result.
				$existing = $this->bundles->findByKey($uid, $firm, $node->getId(), $key);
				return $this->resume($existing, $fingerprint, $node, $sharer);
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->process($bundle, $node, $sharer, $message, $name);
		return $this->describe($bundle);
	}

	/** Retry failed recipients. */
	public function retry(IUser $sharer, int $bundleId): array {
		$bundle = $this->owned($sharer, $bundleId);
		$node = $this->shareableNode($sharer->getUID(), $bundle->getFileId());
		$this->recover($bundle, $node);
		$this->process($bundle, $node, $sharer, $bundle->getMessage() ?? '', $bundle->getName());
		return $this->describe($bundle);
	}

	/** @return list<array> the caller's bundles on this item, after recovery */
	public function listForFile(IUser $user, int $fileId): array {
		$firm = $this->firms->firmOf($user);
		$node = $this->accessibleNode($user->getUID(), $fileId);
		$out = [];
		foreach ($this->bundles->findForFile($user->getUID(), $firm, $node->getId()) as $bundle) {
			if ($bundle->getStatus() === Bundle::IN_PROGRESS) {
				$this->recover($bundle, $node);
			}
			$out[] = $this->describe($bundle);
		}
		return $out;
	}

	public function get(IUser $user, int $bundleId): array {
		$bundle = $this->owned($user, $bundleId);
		$node = $this->accessibleNode($user->getUID(), $bundle->getFileId());
		if ($bundle->getStatus() === Bundle::IN_PROGRESS) {
			$this->recover($bundle, $node);
		}
		return $this->describe($bundle);
	}

	/**
	 * Apply edits recipient by recipient. Every share the save touches must carry
	 * the version it was loaded with; if any was changed elsewhere since then,
	 * nothing is written and the caller reloads.
	 *
	 * @param array{name?: mixed, message?: mixed, permissions?: mixed, expireDate?: mixed,
	 *   versions?: mixed, add?: mixed, idempotencyKey?: mixed} $req
	 */
	public function update(IUser $user, int $bundleId, array $req): array {
		$uid = $user->getUID();
		$firm = $this->firms->firmOf($user);
		$bundle = $this->owned($user, $bundleId);
		$node = $this->shareableNode($uid, $bundle->getFileId());
		if (!$this->policy->downloadAllowed($uid, $node)) {
			throw new FirmException($this->l->t('This item was shared with you without download rights, so it can\'t be shared through BetterSharing.'));
		}
		$name = $this->validateName($req['name'] ?? $bundle->getName());
		$message = $this->validateMessage($req['message'] ?? ($bundle->getMessage() ?? ''));
		$permissions = $this->policy->validateRequested((int)($req['permissions'] ?? 0));
		$versions = is_array($req['versions'] ?? null) ? $req['versions'] : [];
		$rows = $this->recipients->findForBundle($bundle->getId());
		$outsideAllowed = $this->readiness->problem() === null;

		$toAdd = is_array($req['add'] ?? null) && count($req['add']) > 0
			? $this->resolver->resolve($user, $firm, $req['add'], $outsideAllowed)
			: [];
		$expireDate = $this->validateExpireDate($req['expireDate'] ?? null, array_merge(
			array_map(fn (Recipient $r) => ['kind' => $r->getKind()], array_filter($rows, fn ($r) => $r->getStatus() === Recipient::SHARED)),
			$toAdd,
		));

		$targets = $this->checkVersions($uid, $rows, $versions);

		$bundle->setName($name);
		$bundle->setMessage($message === '' ? null : $message);
		$bundle->setUpdatedAt($this->time->getTime());
		$this->bundles->update($bundle);

		foreach ($targets as [$row, $share]) {
			try {
				$p = $this->policy->forRecipient($permissions, $row->getKind(), $node);
				$share->setPermissions($p);
				$share->setNote($message);
				if ($row->getKind() === Recipient::KIND_EMAIL) {
					$share->setLabel($name);
					$share->setExpirationDate($expireDate === null ? null : new DateTime($expireDate . ' 00:00:00'));
				}
				self::allowDownload($share);
				$this->shareManager->updateShare($share);
				$row->setPermissions($p);
				$row->setExpireDate($row->getKind() === Recipient::KIND_EMAIL ? $expireDate : null);
				$this->setRow($row, Recipient::SHARED, $this->l->t('Saved'));
			} catch (\Throwable $e) {
				$this->logger->warning('BetterSharing could not update a share', ['exception' => $e]);
				$this->setRow($row, Recipient::SHARED, $this->l->t('Not changed: %s', [self::reason($e, $this->l)]));
			}
		}

		if (count($toAdd) > 0) {
			$now = $this->time->getTime();
			foreach ($toAdd as $r) {
				if ($this->hasRecipient($rows, $r['kind'], $r['recipient'])) {
					continue;
				}
				$row = new Recipient();
				$row->setBundleId($bundle->getId());
				$row->setKind($r['kind']);
				$row->setRecipient($r['recipient']);
				$row->setDisplay($r['display']);
				$row->setPermissions($this->policy->forRecipient($permissions, $r['kind'], $node));
				$row->setExpireDate($r['kind'] === Recipient::KIND_EMAIL ? $expireDate : null);
				$existing = $this->existingShare($node, $r['kind'], $r['recipient']);
				$row->setStatus($existing === null ? Recipient::PENDING : Recipient::EXISTING);
				$row->setExistingShareId($existing?->getFullId());
				$row->setResult($existing === null ? null : $this->l->t('Already has access'));
				$row->setUpdatedAt($now);
				$this->recipients->insert($row);
			}
			$this->process($bundle, $node, $user, $message, $name);
		}
		return $this->describe($bundle);
	}

	/** @param string $version the version of this person's share when the editor loaded it */
	public function removeRecipient(IUser $user, int $bundleId, int $rowId, string $version): array {
		$bundle = $this->owned($user, $bundleId);
		$this->accessibleNode($user->getUID(), $bundle->getFileId());
		$row = $this->rowIn($bundle, $rowId);
		foreach ($this->checkVersions($user->getUID(), [$row], [(string)$row->getId() => $version]) as [$r, $share]) {
			$this->unshareRow($user, $r, $share);
		}
		return $this->describe($bundle);
	}

	/** @param array<string, string> $versions row ID to the version loaded in the editor */
	public function stopSharing(IUser $user, int $bundleId, array $versions): array {
		$bundle = $this->owned($user, $bundleId);
		$this->accessibleNode($user->getUID(), $bundle->getFileId());
		$rows = $this->recipients->findForBundle($bundle->getId());
		foreach ($this->checkVersions($user->getUID(), $rows, $versions) as [$row, $share]) {
			$this->unshareRow($user, $row, $share);
		}
		return $this->describe($bundle);
	}

	/** Sets a new password; Nextcloud emails it to the recipient. BetterSharing never keeps it. */
	public function sendNewPassword(IUser $user, int $bundleId, int $rowId): array {
		$bundle = $this->owned($user, $bundleId);
		$this->accessibleNode($user->getUID(), $bundle->getFileId());
		$row = $this->rowIn($bundle, $rowId);
		if ($row->getKind() !== Recipient::KIND_EMAIL || $row->getStatus() !== Recipient::SHARED) {
			throw new FirmException($this->l->t('Only people outside your firm have a password.'));
		}
		if (($problem = $this->readiness->problem()) !== null) {
			throw new FirmException($problem);
		}
		$share = $this->loadShare((string)$row->getShareId());
		if ($share === null) {
			$this->setRow($row, Recipient::REMOVED, $this->l->t('This share was removed outside BetterSharing.'));
			return $this->describe($bundle);
		}
		if (!PermissionPolicy::canEdit($user->getUID(), $share)) {
			throw new FirmException($this->l->t('You can only change shares you created.'));
		}
		try {
			$share->setPassword($this->newPassword());
			$this->shareManager->updateShare($share);
			$this->setRow($row, Recipient::SHARED, $this->l->t('New password sent'));
		} catch (\Throwable $e) {
			$this->logger->warning('BetterSharing could not set a new password', ['exception' => $e]);
			$this->setRow($row, Recipient::SHARED, $this->l->t('New password not sent: %s', [self::reason($e, $this->l)]));
			$this->notifier->notifyFailures($user->getUID(), $bundle, [$row]);
		}
		return $this->describe($bundle);
	}

	// Internals

	/** @throws FirmException */
	private function shareableNode(string $uid, int $fileId): Node {
		$node = $fileId > 0 ? $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) : null;
		if ($node === null) {
			throw new FirmException($this->l->t('This item no longer exists or you no longer have access to it.'));
		}
		if (!$node->isShareable()) {
			throw new FirmException($this->l->t('You are not allowed to share this item.'));
		}
		return $node;
	}

	/** The item as the user can see it now; refuses when they lost access. */
	private function accessibleNode(string $uid, int $fileId): Node {
		$node = $fileId > 0 ? $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) : null;
		if ($node === null) {
			throw new FirmException($this->l->t('This item no longer exists or you no longer have access to it.'));
		}
		return $node;
	}

	/** A bundle the user created, in the firm they belong to now. @throws FirmException */
	private function owned(IUser $user, int $bundleId): Bundle {
		$firm = $this->firms->firmOf($user);
		try {
			return $this->bundles->findOwned($bundleId, $user->getUID(), $firm);
		} catch (DoesNotExistException) {
			throw new FirmException($this->l->t('This share no longer exists.'));
		}
	}

	/**
	 * Checks, before anything is written, that every live share a change will
	 * touch still matches the version the editor loaded. Shares removed elsewhere
	 * are marked removed; shares the caller did not create are left alone.
	 *
	 * @param Recipient[] $rows
	 * @return list<array{0: Recipient, 1: IShare}> the rows and shares to change
	 * @throws FirmException when any share is missing its version or changed elsewhere
	 */
	private function checkVersions(string $uid, array $rows, mixed $versions): array {
		$versions = is_array($versions) ? $versions : [];
		$targets = [];
		$missing = [];
		$conflict = false;
		foreach ($rows as $row) {
			if ($row->getStatus() !== Recipient::SHARED || $row->getShareId() === null) {
				continue;
			}
			$share = $this->loadShare($row->getShareId());
			if ($share === null) {
				$missing[] = $row; // recorded only once the whole check has passed
				continue;
			}
			if (!PermissionPolicy::canEdit($uid, $share)) {
				continue;
			}
			$loaded = $versions[(string)$row->getId()] ?? null;
			if (!is_string($loaded) || !hash_equals(self::version($share), $loaded)) {
				$conflict = true;
				continue;
			}
			$targets[] = [$row, $share];
		}
		if ($conflict) {
			throw new FirmException($this->l->t('Someone changed this share somewhere else since you opened it. Nothing was saved. Reload it and make your changes again.'));
		}
		foreach ($missing as $row) {
			$row->setShareId(null);
			$this->setRow($row, Recipient::REMOVED, $this->l->t('This share was removed outside BetterSharing.'));
		}
		return $targets;
	}

	/** @throws FirmException */
	private function rowIn(Bundle $bundle, int $rowId): Recipient {
		try {
			return $this->recipients->findInBundle($rowId, $bundle->getId());
		} catch (DoesNotExistException) {
			throw new FirmException($this->l->t('That person is not part of this share.'));
		}
	}

	private function resume(Bundle $bundle, string $fingerprint, Node $node, IUser $sharer): array {
		if (!hash_equals($bundle->getFingerprint(), $fingerprint)) {
			throw new FirmException($this->l->t('This share request was already used for a different share. Close the dialog and start again.'));
		}
		$this->recover($bundle, $node);
		$this->process($bundle, $node, $sharer, $bundle->getMessage() ?? '', $bundle->getName(), retryFailed: false);
		return $this->describe($bundle);
	}

	/**
	 * Resolve rows interrupted between the share call and recording its ID.
	 * A share made by the owner for that recipient after the claim is adopted;
	 * otherwise the row is reported as failed.
	 */
	public function recover(Bundle $bundle, Node $node): void {
		$now = $this->time->getTime();
		foreach ($this->recipients->findForBundle($bundle->getId()) as $row) {
			if ($row->getStatus() !== Recipient::CLAIMED || $row->getShareId() !== null) {
				continue;
			}
			if ($now - (int)$row->getClaimedAt() < self::STALE_CLAIM_SECONDS) {
				continue; // another request may still be working on it
			}
			$adopted = false;
			foreach ($this->sharesFor($node, $row->getKind(), $row->getRecipient(), $bundle->getOwner()) as $share) {
				$created = $share->getShareTime()?->getTimestamp() ?? 0;
				if ($created >= (int)$row->getClaimedAt() - 5 && $this->takeOwnership($row, $share)) {
					$adopted = true;
					break;
				}
			}
			if (!$adopted) {
				$this->setRow($row, Recipient::FAILED, $this->l->t('Interrupted before sharing finished. Not shared.'));
			}
		}
	}

	/**
	 * Share with every pending (and, when retrying, failed) recipient. Each row is
	 * claimed before the share call; the claim is atomic, so concurrent requests
	 * never share the same row twice.
	 */
	private function process(Bundle $bundle, Node $node, IUser $sharer, string $message, string $name, bool $retryFailed = true): void {
		$failed = [];
		$outsideAllowed = $this->readiness->problem() === null;
		foreach ($this->recipients->findForBundle($bundle->getId()) as $row) {
			$eligible = $row->getStatus() === Recipient::PENDING
				|| ($retryFailed && $row->getStatus() === Recipient::FAILED);
			if (!$eligible) {
				continue;
			}
			// Firm and group membership may have changed since the request was recorded.
			$reason = $this->resolver->stillAllowed($sharer, $bundle->getFirm(), $row->getKind(), $row->getRecipient(), $outsideAllowed);
			if ($reason !== null) {
				$this->setRow($row, Recipient::FAILED, $reason);
				$failed[] = $row;
				continue;
			}
			$claimedAt = $this->time->getTime();
			if (!$this->recipients->claim($row->getId(), $claimedAt)) {
				continue; // another request has it
			}
			$row->setStatus(Recipient::CLAIMED);
			$row->setClaimedAt($claimedAt);
			if ($row->getKind() === Recipient::KIND_EMAIL && ($problem = $this->readiness->problem()) !== null) {
				$this->setRow($row, Recipient::FAILED, $problem);
				$failed[] = $row;
				continue;
			}
			try {
				$share = $this->shareManager->createShare($this->buildShare($node, $sharer, $row, $message, $name));
				$created = $share->getShareTime()?->getTimestamp() ?? $claimedAt;
				if ($created < $claimedAt - 5) {
					// Nextcloud returned a share that already existed; never treat it as ours.
					$this->markExisting($row, $share);
				} else {
					$this->takeOwnership($row, $share);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('BetterSharing could not share with a recipient', ['exception' => $e]);
				$row->setShareId(null);
				$this->setRow($row, Recipient::FAILED, self::reason($e, $this->l));
				$failed[] = $row;
			}
		}

		$open = array_filter(
			$this->recipients->findForBundle($bundle->getId()),
			fn (Recipient $r) => in_array($r->getStatus(), [Recipient::PENDING, Recipient::CLAIMED], true),
		);
		if (count($open) === 0) {
			// Completion and the preset's usage count commit together or not at all.
			$this->db->beginTransaction();
			try {
				if ($this->bundles->markComplete($bundle->getId(), $this->time->getTime())) {
					if ($bundle->getPresetId() !== null) {
						$this->presets->recordUse($bundle->getPresetId(), $this->time->getTime());
					}
					$bundle->setStatus(Bundle::COMPLETE);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
		if (count($failed) > 0) {
			$this->notifier->notifyFailures($sharer->getUID(), $bundle, $failed);
		}
	}

	private function buildShare(Node $node, IUser $sharer, Recipient $row, string $message, string $name): IShare {
		$share = $this->shareManager->newShare();
		$share->setNode($node);
		$share->setShareType(self::TYPE_OF[$row->getKind()]);
		$share->setSharedWith($row->getRecipient());
		$share->setSharedBy($sharer->getUID());
		$share->setPermissions($row->getPermissions());
		$share->setNote($message);
		self::allowDownload($share);
		if ($row->getKind() === Recipient::KIND_EMAIL) {
			// Nextcloud requires the password up front. It hashes it and emails it to
			// the recipient; BetterSharing never stores it.
			$share->setPassword($this->newPassword());
			$share->setLabel($name);
			$share->setSendPasswordByTalk(false);
			$share->setMailSend(true);
			if ($row->getExpireDate() !== null) {
				$share->setExpirationDate(new DateTime($row->getExpireDate() . ' 00:00:00'));
			}
		}
		return $share;
	}

	/** A password that satisfies the server's password policy, when one is installed. */
	private function newPassword(): string {
		$event = new GenerateSecurePasswordEvent(PasswordContext::SHARING);
		$this->events->dispatchTyped($event);
		return $event->getPassword() ?? $this->random->generate(20, ISecureRandom::CHAR_HUMAN_READABLE);
	}

	private static function allowDownload(IShare $share): void {
		$share->setHideDownload(false);
		$attributes = $share->getAttributes() ?? $share->newAttributes();
		$attributes->setAttribute('permissions', 'download', true);
		$share->setAttributes($attributes);
	}

	/** An existing share giving this recipient access to exactly this item. */
	private function existingShare(Node $node, string $kind, string $recipient): ?IShare {
		$owner = $node->getOwner()?->getUID();
		foreach ($this->sharesFor($node, $kind, $recipient, $owner) as $share) {
			return $share;
		}
		return null;
	}

	/** @return IShare[] */
	private function sharesFor(Node $node, string $kind, string $recipient, ?string $owner): array {
		$type = self::TYPE_OF[$kind];
		$candidates = [];
		foreach (array_unique(array_filter([$owner, $node->getOwner()?->getUID()])) as $uid) {
			foreach ($this->shareManager->getSharesBy($uid, $type, $node, true, -1, 0) as $share) {
				$candidates[$share->getFullId()] = $share;
			}
		}
		return array_values(array_filter($candidates, function (IShare $s) use ($kind, $recipient) {
			$with = (string)$s->getSharedWith();
			return $kind === Recipient::KIND_EMAIL ? mb_strtolower($with) === $recipient : $with === $recipient;
		}));
	}

	/** Deletes a share this row owns; checkVersions() has already vetted it. */
	private function unshareRow(IUser $user, Recipient $row, IShare $share): void {
		if (!PermissionPolicy::canEdit($user->getUID(), $share)) {
			throw new FirmException($this->l->t('You can only change shares you created.'));
		}
		$this->shareManager->deleteShare($share);
		$row->setShareId(null);
		$this->setRow($row, Recipient::REMOVED, $this->l->t('Removed'));
	}

	/**
	 * Records that this row created the share. The unique index on share_id makes
	 * ownership exclusive: if another row already owns it, this row is recorded as
	 * pre-existing access instead, and returns false.
	 */
	private function takeOwnership(Recipient $row, IShare $share): bool {
		$row->setShareId($share->getFullId());
		try {
			$this->setRow($row, Recipient::SHARED, $this->l->t('Shared'));
			return true;
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$row->setShareId(null);
			$this->markExisting($row, $share);
			return false;
		}
	}

	private function markExisting(Recipient $row, IShare $share): void {
		$row->setShareId(null);
		$row->setExistingShareId($share->getFullId());
		$this->setRow($row, Recipient::EXISTING, $this->l->t('Already has access'));
	}

	private function loadShare(string $fullId): ?IShare {
		try {
			return $this->shareManager->getShareById($fullId);
		} catch (ShareNotFound) {
			return null;
		}
	}

	private function setRow(Recipient $row, string $status, ?string $result): void {
		$row->setStatus($status);
		$row->setResult($result === null ? null : mb_substr($result, 0, 500));
		$row->setUpdatedAt($this->time->getTime());
		$this->recipients->update($row);
	}

	/** @param Recipient[] $rows */
	private function hasRecipient(array $rows, string $kind, string $recipient): bool {
		foreach ($rows as $r) {
			if ($r->getKind() === $kind && $r->getRecipient() === $recipient && $r->getStatus() !== Recipient::REMOVED) {
				return true;
			}
		}
		return false;
	}

	/** @throws FirmException */
	private function validateExpireDate(mixed $value, array $recipients): ?string {
		$hasOutside = count(array_filter($recipients, fn ($r) => ($r['kind'] ?? '') === Recipient::KIND_EMAIL)) > 0;
		if (!$hasOutside || $value === null || $value === '') {
			if ($hasOutside && $this->shareManager->shareApiLinkDefaultExpireDateEnforced()) {
				throw new FirmException($this->l->t('This server requires an end date for links to people outside your firm.'));
			}
			return null;
		}
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || DateTime::createFromFormat('!Y-m-d', $value) === false) {
			throw new FirmException($this->l->t('Pick a valid end date.'));
		}
		$today = (new DateTime('@' . $this->time->getTime()))->format('Y-m-d');
		if ($value <= $today) {
			throw new FirmException($this->l->t('Pick an end date after today.'));
		}
		if ($this->shareManager->shareApiLinkDefaultExpireDateEnforced()) {
			$max = (new DateTime('@' . $this->time->getTime()))->modify('+' . $this->shareManager->shareApiLinkDefaultExpireDays() . ' days')->format('Y-m-d');
			if ($value > $max) {
				throw new FirmException($this->l->t('This server allows links to people outside your firm for at most %d days.', [$this->shareManager->shareApiLinkDefaultExpireDays()]));
			}
		}
		return $value;
	}

	/** @throws FirmException */
	private function validateName(mixed $value): string {
		$name = is_string($value) ? trim($value) : '';
		if ($name === '') {
			throw new FirmException($this->l->t('Give the share a name.'));
		}
		return mb_substr($name, 0, 255);
	}

	/** @throws FirmException */
	private function validateMessage(mixed $value): string {
		$message = is_string($value) ? trim($value) : '';
		if (mb_strlen($message) > 10000) {
			throw new FirmException($this->l->t('The message is too long. Keep it under 10,000 characters.'));
		}
		return $message;
	}

	/** A short fingerprint of the editable fields, used to detect changes made elsewhere. */
	public static function version(IShare $share): string {
		return substr(hash('sha256', json_encode([
			$share->getPermissions(),
			$share->getExpirationDate()?->format('Y-m-d'),
			$share->getLabel(),
			$share->getNote(),
			$share->getHideDownload(),
		])), 0, 16);
	}

	private static function reason(\Throwable $e, IL10N $l): string {
		if ($e instanceof \InvalidArgumentException && $e->getMessage() !== '') {
			return $e->getMessage(); // Nextcloud's sharing checks use translated, user-facing messages
		}
		if ($e instanceof \OCP\HintException) {
			$hint = $e->getHint();
			if ($hint !== '') {
				return $hint;
			}
		}
		return $l->t('Nextcloud could not create this share.');
	}

	public function describe(Bundle $bundle): array {
		$rows = $this->recipients->findForBundle($bundle->getId());
		$out = [];
		foreach ($rows as $row) {
			$version = null;
			$share = null;
			if ($row->getStatus() === Recipient::SHARED && $row->getShareId() !== null) {
				$share = $this->loadShare($row->getShareId());
				if ($share === null) {
					$row->setShareId(null);
					$this->setRow($row, Recipient::REMOVED, $this->l->t('This share was removed outside BetterSharing.'));
				} else {
					$version = self::version($share);
				}
			}
			$out[] = [
				'id' => $row->getId(),
				'kind' => $row->getKind(),
				'recipient' => $row->getRecipient(),
				'display' => $row->getDisplay(),
				'status' => $row->getStatus(),
				'result' => $row->getResult(),
				'permissions' => $share?->getPermissions() ?? $row->getPermissions(),
				'expireDate' => $share !== null ? $share->getExpirationDate()?->format('Y-m-d') : $row->getExpireDate(),
				'version' => $version,
				'editable' => $share !== null && PermissionPolicy::canEdit($bundle->getOwner(), $share),
			];
		}
		return [
			'id' => $bundle->getId(),
			'fileId' => $bundle->getFileId(),
			'name' => $bundle->getName(),
			'message' => $bundle->getMessage() ?? '',
			'presetId' => $bundle->getPresetId(),
			'presetName' => $bundle->getPresetName(),
			'status' => $bundle->getStatus(),
			'createdAt' => $bundle->getCreatedAt(),
			'recipients' => $out,
		];
	}
}

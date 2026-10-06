<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCA\BetterSharing\Db\Recipient;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * BetterSharing's permission rules. Four choices map to Nextcloud bits:
 * view and download (READ), upload and create folders (CREATE), edit (UPDATE),
 * delete (DELETE). Resharing (SHARE) is never granted.
 *
 * The download and edit rules are BetterSharing's own conservative rules built
 * on public IManager and IShare data. They are stricter than Nextcloud's
 * private controller checks, never looser.
 */
class PermissionPolicy {
	public const ALLOWED = Constants::PERMISSION_READ | Constants::PERMISSION_CREATE
		| Constants::PERMISSION_UPDATE | Constants::PERMISSION_DELETE;

	/** Share types that can carry a download restriction to the sharer. */
	private const INCOMING_TYPES = [
		IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_CIRCLE,
		IShare::TYPE_ROOM, IShare::TYPE_DECK, IShare::TYPE_SCIENCEMESH,
	];

	public function __construct(
		private IManager $shareManager,
		private IRootFolder $rootFolder,
		private IL10N $l,
	) {
	}

	/** @throws FirmException */
	public function validateRequested(int $permissions): int {
		$permissions &= ~Constants::PERMISSION_SHARE;
		if (($permissions & ~self::ALLOWED) !== 0 || $permissions === 0) {
			throw new FirmException($this->l->t('Choose at least one thing people can do.'));
		}
		if ($permissions & (Constants::PERMISSION_UPDATE | Constants::PERMISSION_DELETE)) {
			$permissions |= Constants::PERMISSION_READ;
		}
		return $permissions;
	}

	/**
	 * The permissions one recipient actually gets.
	 *
	 * @throws FirmException when the combination cannot be granted to this recipient
	 */
	public function forRecipient(int $requested, string $kind, Node $node): int {
		$p = $this->validateRequested($requested);
		if (!$node instanceof Folder) {
			// Nextcloud drops CREATE and DELETE on single files.
			$p &= ~(Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE);
		}
		if ($kind !== Recipient::KIND_EMAIL) {
			// People at the firm always get view and download.
			$p |= Constants::PERMISSION_READ;
		}
		if (($p & Constants::PERMISSION_READ) === 0 && ($p & Constants::PERMISSION_CREATE) === 0) {
			throw new FirmException($this->l->t('Upload only works on folders. Choose view and download for a single file.'));
		}
		return $p;
	}

	/**
	 * Conservative download rule. Refuses when any share that reaches the item
	 * for the sharer (on the item or any parent folder) forbids or hides download.
	 */
	public function downloadAllowed(string $uid, Node $node): bool {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$candidates = $userFolder->getById($node->getId());
		if (count($candidates) === 0) {
			return false; // cannot see how the item reaches this user: fail closed
		}
		foreach ($candidates as $candidate) {
			$current = $candidate;
			while (true) {
				foreach (self::INCOMING_TYPES as $type) {
					if (!$this->shareManager->shareProviderExists($type)) {
						continue;
					}
					$shares = $this->shareManager->getSharedWith($uid, $type, $current, -1, 0);
					foreach ($shares as $share) {
						if (!self::shareAllowsDownload($share)) {
							return false;
						}
					}
				}
				$parent = AccessService::parentIn($userFolder, $current);
				if ($parent === null) {
					break;
				}
				$current = $parent;
			}
		}
		return true;
	}

	public static function shareAllowsDownload(IShare $share): bool {
		if ($share->getHideDownload()) {
			return false;
		}
		$attributes = $share->getAttributes();
		return $attributes === null || $attributes->getAttribute('permissions', 'download') !== false;
	}

	/** Conservative edit rule: only the share's owner or the person who created it. */
	public static function canEdit(string $uid, IShare $share): bool {
		return $share->getShareOwner() === $uid || $share->getSharedBy() === $uid;
	}
}

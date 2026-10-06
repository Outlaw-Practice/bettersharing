<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * "Who has access": every share of the item and of the folders above it.
 * The item's owner sees all of them; anyone else sees only the shares they
 * created and the share that gave them access. Shares the viewer did not
 * create are read-only (conservative edit rule). Accounts and groups outside
 * the viewer's firm are never named: they appear as "Someone outside your firm".
 */
class AccessService {
	private const TYPES = [
		IShare::TYPE_USER => 'user',
		IShare::TYPE_GROUP => 'group',
		IShare::TYPE_EMAIL => 'email',
		IShare::TYPE_LINK => 'link',
	];

	public function __construct(
		private FirmService $firms,
		private IManager $shareManager,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IL10N $l,
	) {
	}

	/** @throws FirmException */
	public function list(IUser $viewer, int $fileId): array {
		$firm = $this->firms->firmOf($viewer);
		$firmGroups = $this->firms->shareableGroups($firm);
		$uid = $viewer->getUID();
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$node = $userFolder->getFirstNodeById($fileId);
		if ($node === null) {
			throw new FirmException($this->l->t('This item no longer exists or you no longer have access to it.'));
		}
		$entries = [];
		$current = $node;
		$depth = 0;
		while (true) {
			$isOwner = $current->getOwner()?->getUID() === $uid;
			foreach ($this->sharesOn($current) as $share) {
				$mine = $isOwner || $share->getSharedBy() === $uid || $this->sharedWithMe($uid, $share);
				if ($mine) {
					$entries[$share->getFullId()] ??= $this->entry($uid, $firm, $firmGroups, $share, $depth === 0 ? null : $current->getName());
				}
			}
			$parent = self::parentIn($userFolder, $current);
			if ($parent === null) {
				break;
			}
			$current = $parent;
			$depth++;
		}
		return array_values($entries);
	}

	/** The parent folder, looked up through the user's own file tree; null at the top. */
	public static function parentIn(\OCP\Files\Folder $userFolder, Node $node): ?Node {
		$rel = $userFolder->getRelativePath($node->getPath());
		if ($rel === null || $rel === '/' || $rel === '') {
			return null;
		}
		$parentRel = dirname($rel);
		return $parentRel === '/' || $parentRel === '.' ? $userFolder : $userFolder->get($parentRel);
	}

	/** @return IShare[] */
	private function sharesOn(Node $node): array {
		$owner = $node->getOwner()?->getUID();
		if ($owner === null) {
			return [];
		}
		$out = [];
		foreach (array_keys(self::TYPES) as $type) {
			if (!$this->shareManager->shareProviderExists($type)) {
				continue;
			}
			foreach ($this->shareManager->getSharesBy($owner, $type, $node, true, -1, 0) as $share) {
				$out[] = $share;
			}
		}
		return $out;
	}

	private function sharedWithMe(string $uid, IShare $share): bool {
		return match ($share->getShareType()) {
			IShare::TYPE_USER => $share->getSharedWith() === $uid,
			IShare::TYPE_GROUP => $this->groupManager->isInGroup($uid, (string)$share->getSharedWith()),
			default => false,
		};
	}

	/** @param array<string, mixed> $firmGroups the viewer firm's single-firm groups */
	private function entry(string $viewer, string $firm, array $firmGroups, IShare $share, ?string $inheritedFrom): array {
		$kind = self::TYPES[$share->getShareType()] ?? 'other';
		$with = (string)$share->getSharedWith();
		$outside = $this->l->t('Someone outside your firm');
		$insideFirm = match ($kind) {
			'user' => $this->firms->isMember($with, $firm),
			'group' => isset($firmGroups[$with]),
			default => true, // email addresses and links carry no account identity
		};
		if (!$insideFirm) {
			$kind = 'outside';
			$with = '';
		}
		$display = match ($kind) {
			'user' => $this->userManager->getDisplayName($with) ?? $with,
			'group' => $this->groupManager->get($with)?->getDisplayName() ?? $with,
			'link' => $share->getLabel() !== '' ? $share->getLabel() : $this->l->t('Public link'),
			'outside' => $outside,
			default => $with,
		};
		$by = (string)$share->getSharedBy();
		$byInFirm = $this->firms->isMember($by, $firm);
		return [
			'shareId' => $share->getFullId(),
			'kind' => $kind,
			'with' => $with,
			'display' => $display,
			'permissions' => $share->getPermissions(),
			'expireDate' => $share->getExpirationDate()?->format('Y-m-d'),
			'sharedBy' => $byInFirm ? $by : '',
			'sharedByDisplay' => $byInFirm ? ($this->userManager->getDisplayName($by) ?? $by) : $outside,
			'sharedWithYou' => $this->sharedWithMe($viewer, $share),
			'editable' => $inheritedFrom === null && PermissionPolicy::canEdit($viewer, $share),
			'inheritedFrom' => $inheritedFrom,
		];
	}
}

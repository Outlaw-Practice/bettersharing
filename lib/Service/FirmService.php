<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\Group\ISubAdmin;
use OCP\Share\IManager;

/**
 * Firms are Nextcloud groups listed in the app setting "firm_groups"
 * (a JSON array of group IDs). Each user belongs to exactly one firm; anything
 * else fails closed. Nothing here ever looks at another firm's members.
 */
class FirmService {
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private IManager $shareManager,
		private IL10N $l,
	) {
	}

	/** @return string[] */
	public function firmGroupIds(): array {
		$raw = $this->appConfig->getValueString('bettersharing', 'firm_groups', '[]');
		$ids = json_decode($raw, true);
		if (!is_array($ids)) {
			return [];
		}
		return array_values(array_filter($ids, 'is_string'));
	}

	/** @throws FirmException when the user is in no firm or in more than one */
	public function firmOf(IUser $user): string {
		$mine = array_values(array_intersect($this->firmGroupIds(), $this->groupManager->getUserGroupIds($user)));
		if (count($mine) === 0) {
			throw new FirmException($this->l->t('Your account is not part of a firm, so BetterSharing is not available. Ask your administrator to add you to your firm.'));
		}
		if (count($mine) > 1) {
			throw new FirmException($this->l->t('Your account belongs to more than one firm, so BetterSharing is not available. Ask your administrator to fix your firm membership.'));
		}
		return $mine[0];
	}

	public function firmName(string $firm): string {
		return $this->groupManager->get($firm)?->getDisplayName() ?? $firm;
	}

	public function isFirmAdmin(IUser $user, string $firm): bool {
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}
		$group = $this->groupManager->get($firm);
		return $group !== null && $this->subAdmin->isSubAdminOfGroup($user, $group);
	}

	public function isMember(string $uid, string $firm): bool {
		return $this->groupManager->isInGroup($uid, $firm);
	}

	/**
	 * Groups a firm member may share with: the firm group itself and any group
	 * whose members all belong to this firm. Groups that span firms, other
	 * firms' groups, and the admin group are never offered. When a sharer is
	 * given, Nextcloud's own group-sharing rules are applied for them too.
	 *
	 * @return IGroup[]
	 */
	public function shareableGroups(string $firm, ?IUser $sharer = null): array {
		$groups = $this->firmGroups($firm);
		if ($sharer === null) {
			return $groups;
		}
		if (!$this->shareManager->allowGroupSharing()) {
			return [];
		}
		if ($this->shareManager->shareWithGroupMembersOnly()) {
			$mine = $this->groupManager->getUserGroupIds($sharer);
			$excluded = $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList();
			$groups = array_filter($groups, fn ($g, $gid) => in_array((string)$gid, $mine, true) && !in_array((string)$gid, $excluded, true), ARRAY_FILTER_USE_BOTH);
		}
		return $groups;
	}

	/** @return IGroup[] */
	private function firmGroups(string $firm): array {
		$firmGroup = $this->groupManager->get($firm);
		if ($firmGroup === null) {
			return [];
		}
		$result = [$firm => $firmGroup];
		$members = $firmGroup->getUsers();
		$candidates = [];
		foreach ($members as $member) {
			foreach ($this->groupManager->getUserGroupIds($member) as $gid) {
				$candidates[$gid] = true;
			}
		}
		$otherFirms = array_diff($this->firmGroupIds(), [$firm]);
		foreach (array_keys($candidates) as $gid) {
			$gid = (string)$gid;
			if ($gid === $firm || $gid === 'admin' || in_array($gid, $otherFirms, true)) {
				continue;
			}
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				continue;
			}
			$allInFirm = true;
			foreach ($group->getUsers() as $u) {
				if (!$firmGroup->inGroup($u)) {
					$allInFirm = false;
					break;
				}
			}
			if ($allInFirm) {
				$result[$gid] = $group;
			}
		}
		return $result;
	}
}

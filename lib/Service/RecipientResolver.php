<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCA\BetterSharing\Db\Recipient;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;

/**
 * Turns what the user typed into recipients, scoped to the sharer's firm.
 * People at the firm resolve to accounts; every other address is an ordinary
 * email recipient. Firms are fully separate: the outcome never depends on
 * whether an address has an account at another firm.
 */
class RecipientResolver {
	public function __construct(
		private FirmService $firms,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IMailer $mailer,
		private IL10N $l,
	) {
	}

	/** @return list<array{type: string, id: string, label: string}> */
	public function search(IUser $sharer, string $firm, string $query, bool $outsideAllowed): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		$results = [];
		foreach ($this->groupManager->displayNamesInGroup($firm, $query, 10) as $uid => $name) {
			if ((string)$uid !== $sharer->getUID()) {
				$results[] = ['type' => Recipient::KIND_USER, 'id' => (string)$uid, 'label' => (string)$name];
			}
		}
		foreach ($this->firmUsersByEmail($firm, $query) as $user) {
			if ($user->getUID() !== $sharer->getUID() && !self::contains($results, Recipient::KIND_USER, $user->getUID())) {
				$results[] = ['type' => Recipient::KIND_USER, 'id' => $user->getUID(), 'label' => $user->getDisplayName()];
			}
		}
		foreach ($this->firms->shareableGroups($firm, $sharer) as $gid => $group) {
			if (mb_stripos($group->getDisplayName(), $query) !== false || mb_stripos((string)$gid, $query) !== false) {
				$results[] = ['type' => Recipient::KIND_GROUP, 'id' => (string)$gid, 'label' => $group->getDisplayName()];
			}
		}
		if ($outsideAllowed && $this->mailer->validateMailAddress($query) && count($this->firmUsersByEmail($firm, $query)) === 0) {
			$email = mb_strtolower($query);
			$results[] = ['type' => Recipient::KIND_EMAIL, 'id' => $email, 'label' => $email];
		}
		return array_slice($results, 0, 15);
	}

	/**
	 * Re-resolves everything the browser sent. Never trusts a type or label.
	 *
	 * @param array<array{type?: mixed, id?: mixed}> $input
	 * @return list<array{kind: string, recipient: string, display: string}>
	 * @throws FirmException
	 */
	public function resolve(IUser $sharer, string $firm, array $input, bool $outsideAllowed): array {
		if (count($input) === 0) {
			throw new FirmException($this->l->t('Add at least one person.'));
		}
		$out = [];
		$seen = [];
		foreach ($input as $item) {
			$type = is_string($item['type'] ?? null) ? $item['type'] : '';
			$id = is_string($item['id'] ?? null) ? trim($item['id']) : '';
			$resolved = match ($type) {
				Recipient::KIND_USER => $this->resolveUser($sharer, $firm, $id),
				Recipient::KIND_GROUP => $this->resolveGroup($sharer, $firm, $id),
				Recipient::KIND_EMAIL => $this->resolveEmail($sharer, $firm, $id, $outsideAllowed),
				default => throw new FirmException($this->l->t('One of the people on the list could not be recognized. Remove it and add it again.')),
			};
			$key = $resolved['kind'] . ':' . $resolved['recipient'];
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$out[] = $resolved;
			}
		}
		return $out;
	}

	/**
	 * Re-checks a stored recipient just before sharing with it, because firm and
	 * group membership can change after the request was recorded.
	 *
	 * @return string|null null when still allowed, otherwise the reason
	 */
	public function stillAllowed(IUser $sharer, string $firm, string $kind, string $recipient, bool $outsideAllowed): ?string {
		try {
			match ($kind) {
				Recipient::KIND_USER => $this->resolveUser($sharer, $firm, $recipient),
				Recipient::KIND_GROUP => $this->resolveGroup($sharer, $firm, $recipient),
				Recipient::KIND_EMAIL => $this->resolveEmail($sharer, $firm, $recipient, $outsideAllowed),
				default => throw new FirmException($this->l->t('This recipient could not be recognized.')),
			};
			if ($kind === Recipient::KIND_EMAIL && count($this->firmUsersByEmail($firm, $recipient)) > 0) {
				// The address now belongs to someone at the firm; an email link is no longer right.
				return $this->l->t('This address now belongs to someone at your firm. Share with them by name instead.');
			}
			return null;
		} catch (FirmException $e) {
			return $e->getMessage();
		}
	}

	private function resolveUser(IUser $sharer, string $firm, string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null || !$this->firms->isMember($user->getUID(), $firm)) {
			throw new FirmException($this->l->t('No one at your firm matches one of the names on the list.'));
		}
		if ($user->getUID() === $sharer->getUID()) {
			throw new FirmException($this->l->t('You already have access to this item. Remove yourself from the list.'));
		}
		return ['kind' => Recipient::KIND_USER, 'recipient' => $user->getUID(), 'display' => $user->getDisplayName()];
	}

	private function resolveGroup(IUser $sharer, string $firm, string $gid): array {
		$groups = $this->firms->shareableGroups($firm, $sharer);
		if (!isset($groups[$gid])) {
			throw new FirmException($this->l->t('One of the groups on the list is not a group at your firm.'));
		}
		return ['kind' => Recipient::KIND_GROUP, 'recipient' => $gid, 'display' => $groups[$gid]->getDisplayName()];
	}

	private function resolveEmail(IUser $sharer, string $firm, string $email, bool $outsideAllowed): array {
		$email = mb_strtolower($email);
		if (!$this->mailer->validateMailAddress($email)) {
			throw new FirmException($this->l->t('%s is not a valid email address.', [$email]));
		}
		// Someone at the firm typed by email address becomes a firm share.
		$firmUsers = $this->firmUsersByEmail($firm, $email);
		$byUid = $this->userManager->get($email);
		if ($byUid !== null && $this->firms->isMember($byUid->getUID(), $firm)) {
			$firmUsers[] = $byUid;
		}
		if (count($firmUsers) > 0) {
			return $this->resolveUser($sharer, $firm, $firmUsers[0]->getUID());
		}
		if (!$outsideAllowed) {
			throw new FirmException($this->l->t('Sharing with people outside your firm is not available right now.'));
		}
		return ['kind' => Recipient::KIND_EMAIL, 'recipient' => $email, 'display' => $email];
	}

	/** @return IUser[] firm members with this email address; other firms are filtered out */
	private function firmUsersByEmail(string $firm, string $email): array {
		if (!str_contains($email, '@')) {
			return [];
		}
		return array_values(array_filter(
			$this->userManager->getByEmail($email),
			fn (IUser $u) => $this->firms->isMember($u->getUID(), $firm),
		));
	}

	private static function contains(array $results, string $type, string $id): bool {
		foreach ($results as $r) {
			if ($r['type'] === $type && $r['id'] === $id) {
				return true;
			}
		}
		return false;
	}
}

<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCA\BetterSharing\Db\Preset;
use OCA\BetterSharing\Db\PresetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\DB\Exception as DbException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IDBConnection;

/** Firm-owned presets. Firm administrators change them; everyone at the firm uses them. */
class PresetService {
	public const PIN_MAX = 4;

	public function __construct(
		private FirmService $firms,
		private PresetMapper $mapper,
		private PermissionPolicy $policy,
		private IDBConnection $db,
		private ITimeFactory $time,
		private IL10N $l,
	) {
	}

	/** @return Preset[] */
	public function list(IUser $user): array {
		$firm = $this->firms->firmOf($user);
		$presets = $this->mapper->findAllForFirm($firm);
		if (count($presets) === 0) {
			$this->seed($firm, $user->getUID());
			$presets = $this->mapper->findAllForFirm($firm);
		}
		return $presets;
	}

	/** @throws FirmException */
	public function create(IUser $user, array $data): Preset {
		$firm = $this->requireAdmin($user);
		return $this->withFirmLock($firm, function () use ($firm, $user, $data) {
			$preset = new Preset();
			$preset->setFirm($firm);
			$preset->setUseCount(0);
			$this->apply($preset, $data, $firm, null);
			$preset->setUpdatedBy($user->getUID());
			$preset->setUpdatedAt($this->time->getTime());
			return $this->insertOrExplain($preset);
		});
	}

	/** @throws FirmException */
	public function update(IUser $user, int $id, array $data): Preset {
		$firm = $this->requireAdmin($user);
		return $this->withFirmLock($firm, function () use ($firm, $user, $id, $data) {
			$preset = $this->find($id, $firm);
			$this->apply($preset, $data, $firm, $id);
			$preset->setUpdatedBy($user->getUID());
			$preset->setUpdatedAt($this->time->getTime());
			return $this->mapper->update($preset);
		});
	}

	/** Existing shares made with the preset keep their settings; bundles keep its name. */
	public function delete(IUser $user, int $id): void {
		$firm = $this->requireAdmin($user);
		$this->withFirmLock($firm, function () use ($firm, $id) {
			$preset = $this->find($id, $firm);
			if (count($this->mapper->findAllForFirm($firm)) <= 1) {
				throw new FirmException($this->l->t('A firm needs at least one preset.'));
			}
			$this->mapper->delete($preset);
			return null;
		});
	}

	/** @throws FirmException */
	private function requireAdmin(IUser $user): string {
		$firm = $this->firms->firmOf($user);
		if (!$this->firms->isFirmAdmin($user, $firm)) {
			throw new FirmException($this->l->t('Only your firm\'s administrators can change presets.'));
		}
		return $firm;
	}

	private function find(int $id, string $firm): Preset {
		try {
			return $this->mapper->findForFirm($id, $firm);
		} catch (DoesNotExistException) {
			throw new FirmException($this->l->t('That preset no longer exists.'));
		}
	}

	/** @throws FirmException */
	private function apply(Preset $preset, array $data, string $firm, ?int $exceptId): void {
		$name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
		if ($name === '' || mb_strlen($name) > 120) {
			throw new FirmException($this->l->t('Give the preset a name of up to 120 characters.'));
		}
		if ($this->mapper->nameTaken($firm, $name, $exceptId)) {
			throw new FirmException($this->l->t('Your firm already has a preset named %s.', [$name]));
		}
		$days = $data['expireDays'] ?? null;
		if ($days !== null && $days !== '') {
			$days = (int)$days;
			if ($days < 1 || $days > 3650) {
				throw new FirmException($this->l->t('Choose an end date between 1 and 3650 days, or no end date.'));
			}
		} else {
			$days = null;
		}
		$message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
		if (mb_strlen($message) > 10000) {
			throw new FirmException($this->l->t('The message is too long. Keep it under 10,000 characters.'));
		}
		$pinned = (bool)($data['pinned'] ?? false);
		if ($pinned && $this->mapper->countPinned($firm, $exceptId) >= self::PIN_MAX) {
			throw new FirmException($this->l->t('Your firm already has %d pinned presets. Unpin one first.', [self::PIN_MAX]));
		}
		$preset->setName($name);
		$preset->setPermissions($this->policy->validateRequested((int)($data['permissions'] ?? 0)));
		$preset->setExpireDays($days);
		$preset->setMessage($message === '' ? null : $message);
		$preset->setPinned($pinned);
	}

	/**
	 * Preset writes for one firm run one at a time: the transaction locks the
	 * firm's preset rows (every firm keeps at least one), so two administrators
	 * pinning at once cannot exceed the pin limit.
	 */
	private function withFirmLock(string $firm, callable $fn): mixed {
		if (count($this->mapper->findAllForFirm($firm)) === 0) {
			$this->seed($firm, 'system');
		}
		$this->db->beginTransaction();
		try {
			$this->mapper->lockFirm($firm);
			$result = $fn();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function insertOrExplain(Preset $preset): Preset {
		try {
			return $this->mapper->insert($preset);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new FirmException($this->l->t('Your firm already has a preset named %s.', [$preset->getName()]));
			}
			throw $e;
		}
	}

	/**
	 * Gives a firm with no presets the three defaults, all in one transaction:
	 * a crash leaves either none or all of them. A concurrent seeder that wins
	 * the unique (firm, name) race makes this one roll back and use its rows.
	 */
	private function seed(string $firm, string $uid): void {
		$defaults = [
			[$this->l->t('View and download'), Constants::PERMISSION_READ, 14, true],
			[$this->l->t('Uploads only'), Constants::PERMISSION_CREATE, 30, true],
			[$this->l->t('Full edit'), Constants::PERMISSION_READ | Constants::PERMISSION_CREATE | Constants::PERMISSION_UPDATE | Constants::PERMISSION_DELETE, 7, false],
		];
		$this->db->beginTransaction();
		try {
			foreach ($defaults as [$name, $perms, $days, $pinned]) {
				$p = new Preset();
				$p->setFirm($firm);
				$p->setName($name);
				$p->setPermissions($perms);
				$p->setExpireDays($days);
				$p->setPinned($pinned);
				$p->setUseCount(0);
				$p->setUpdatedBy($uid);
				$p->setUpdatedAt($this->time->getTime());
				$this->mapper->insert($p);
			}
			$this->db->commit();
		} catch (DbException $e) {
			$this->db->rollBack();
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

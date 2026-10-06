<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Preset> */
class PresetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'bettersharing_presets', Preset::class);
	}

	/** @return Preset[] */
	public function findAllForFirm(string $firm): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('firm', $qb->createNamedParameter($firm)))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findForFirm(int $id, string $firm): Preset {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('firm', $qb->createNamedParameter($firm)));
		return $this->findEntity($qb);
	}

	/** Locks the firm's preset rows until the current transaction ends. */
	public function lockFirm(string $firm): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('firm', $qb->createNamedParameter($firm)))
			->forUpdate();
		$qb->executeQuery()->closeCursor();
	}

	public function countPinned(string $firm, ?int $exceptId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('firm', $qb->createNamedParameter($firm)))
			->andWhere($qb->expr()->eq('pinned', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		if ($exceptId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function nameTaken(string $firm, string $name, ?int $exceptId = null): bool {
		foreach ($this->findAllForFirm($firm) as $preset) {
			if ($preset->getId() !== $exceptId && mb_strtolower($preset->getName()) === mb_strtolower($name)) {
				return true;
			}
		}
		return false;
	}

	/** Atomic increment, so concurrent shares never lose a count. */
	public function recordUse(int $id, int $time): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('use_count', $qb->createFunction('use_count + 1'))
			->set('last_used_at', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}

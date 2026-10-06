<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Bundle> */
class BundleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'bettersharing_bundles', Bundle::class);
	}

	/** @throws DoesNotExistException */
	public function findOwned(int $id, string $owner, string $firm): Bundle {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('firm', $qb->createNamedParameter($firm)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findByKey(string $owner, string $firm, int $fileId, string $key): Bundle {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('firm', $qb->createNamedParameter($firm)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($key)));
		return $this->findEntity($qb);
	}

	/** @return Bundle[] */
	public function findForFile(string $owner, string $firm, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('firm', $qb->createNamedParameter($firm)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Move a bundle from in progress to complete. Returns false when another
	 * request already completed it, so completion side effects run once.
	 */
	public function markComplete(int $id, int $time): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Bundle::COMPLETE))
			->set('updated_at', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Bundle::IN_PROGRESS)));
		return $qb->executeStatement() === 1;
	}
}

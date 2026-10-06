<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Recipient> */
class RecipientMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'bettersharing_recipients', Recipient::class);
	}

	/** @return Recipient[] */
	public function findForBundle(int $bundleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('bundle_id', $qb->createNamedParameter($bundleId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function get(int $id): Recipient {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findInBundle(int $id, int $bundleId): Recipient {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('bundle_id', $qb->createNamedParameter($bundleId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Claim a pending or failed row before the share call. The status check makes
	 * the claim atomic: when two requests race, only one gets the row.
	 */
	public function claim(int $id, int $time): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Recipient::CLAIMED))
			->set('claimed_at', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([Recipient::PENDING, Recipient::FAILED], IQueryBuilder::PARAM_STR_ARRAY)));
		return $qb->executeStatement() === 1;
	}
}

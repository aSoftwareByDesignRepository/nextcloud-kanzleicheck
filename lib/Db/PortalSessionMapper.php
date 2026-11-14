<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<PortalSession> */
class PortalSessionMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'kzc_session', PortalSession::class);
	}

	public function find(int $id): ?PortalSession
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findForUpdate(int $id): ?PortalSession
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<PortalSession> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<PortalSession>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	public function findByTokenHash(string $tokenHash): ?PortalSession
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	// unique-conflict:ok all update sites in this mapper SET revoked_at only —
	// the unique token_hash column is never rewritten by an update path.
	public function revokeForIdentity(int $identityId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return $qb->executeStatement();
	}

	/** @return list<PortalSession> */
	public function findByIdentity(int $identityId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->orderBy('last_seen_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function deleteExpired(\DateTimeImmutable $cutoff, int $limit = 500): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->lt('abs_expires_at', $qb->createNamedParameter($cutoff, 'datetime')),
				$qb->expr()->lt('revoked_at', $qb->createNamedParameter($cutoff, 'datetime')),
			));
		return $qb->executeStatement();
	}
}

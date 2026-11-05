<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Device> */
class DeviceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_device', Device::class);
	}

	public function find(int $id): ?Device
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

	public function findForUpdate(int $id): ?Device
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

	/** @return list<Device> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Device>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	public function findByTokenHash(string $tokenHash): ?Device
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

	/** @return list<Device> */
	public function findByIdentity(int $identityId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->orderBy('last_used_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function countActiveForIdentity(int $identityId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return (int)$qb->executeQuery()->fetchOne();
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

	/** Revoke the oldest-unused devices beyond $keep — ≤50 devices cap. */
	public function revokeOldestBeyond(int $identityId, int $keep): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->orderBy('last_used_at', 'DESC');
		$ids = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) ?: []);
		$excess = array_slice($ids, $keep);
		if ($excess === []) {
			return 0;
		}
		$u = $this->db->getQueryBuilder();
		$u->update($this->getTableName())
			->set('revoked_at', $u->createNamedParameter(new \DateTime(), 'datetime'))
			->where($u->expr()->in('id', $u->createNamedParameter($excess, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		return $u->executeStatement();
	}

	public function deleteExpired(\DateTimeImmutable $cutoff): int
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

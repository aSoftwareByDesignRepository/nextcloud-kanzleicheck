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

/** @template-extends QBMapper<SyncBinding> */
class SyncBindingMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'kzc_sync', SyncBinding::class);
	}

	public function find(int $id): ?SyncBinding
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

	public function findByIdentityAndOrg(int $identityId, int $orgId): ?SyncBinding
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<SyncBinding> */
	public function findByOrg(int $orgId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SyncBinding> */
	public function findByIdentity(int $identityId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Every binding that is not in a terminal state. @return list<SyncBinding> */
	public function findNonTerminal(int $limit = 10000): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->neq('state', $qb->createNamedParameter(SyncBinding::ST_REVOKED)))
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countNonTerminal(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->neq('state', $qb->createNamedParameter(SyncBinding::ST_REVOKED)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Active rw bindings on an org — feeds the US-S14 integrity re-verify
	 * gate (ro bindings cannot write, so they are exempt).
	 */
	public function countActiveRwByOrg(int $orgId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('perms', $qb->createNamedParameter(SyncBinding::PERMS_RW)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(SyncBinding::ST_ACTIVE)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function countByState(string $state): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Cursor-resumable page for the reconcile job. @return list<SyncBinding> */
	public function findPage(int $afterId, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function deleteByIdentity(int $identityId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
		return $qb->executeStatement();
	}
}

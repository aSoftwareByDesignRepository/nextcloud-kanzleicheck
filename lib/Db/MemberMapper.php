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

/** @template-extends QBMapper<Member> */
class MemberMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_member', Member::class);
	}

	public function find(int $id): ?Member
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

	public function findForUpdate(int $id): ?Member
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

	/** @return list<Member> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Member>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	public function findByIdentityAndOrg(int $identityId, int $orgId): ?Member
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Member> */
	public function findByIdentity(int $identityId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
		return $this->findEntities($qb);
	}

	/** @return list<Member> */
	public function findByOrg(int $orgId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		return $this->findEntities($qb);
	}

	public function deleteByIdentityAndOrg(int $identityId, int $orgId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		return $qb->executeStatement() > 0;
	}

	public function countByIdentity(int $identityId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

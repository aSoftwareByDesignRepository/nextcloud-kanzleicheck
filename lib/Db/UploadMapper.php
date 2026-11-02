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

/** @template-extends QBMapper<Upload> */
class UploadMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_upload', Upload::class);
	}

	public function find(int $id): ?Upload
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

	public function findForUpdate(int $id): ?Upload
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

	/** @return list<Upload> */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** @return list<Upload> */
	public function findByOrg(int $orgId, int $limit = 200, string $state = ''): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->orderBy('id', 'DESC')
			->setMaxResults(max(1, min(500, $limit)));
		if ($state !== '') {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}
		return $this->findEntities($qb);
	}

	/** @return list<Upload> upload queue across orgs (staff inbox) */
	public function findByState(string $state, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->orderBy('id', 'DESC')
			->setMaxResults(max(1, min(500, $limit)));
		return $this->findEntities($qb);
	}

	/** Duplicate detection: same org + hash within the trailing window. */
	public function findDuplicate(int $orgId, string $sha256, \DateTimeImmutable $since): ?Upload
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('sha256', $qb->createNamedParameter($sha256)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function countUnreadByOrg(int $orgId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('neu')));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

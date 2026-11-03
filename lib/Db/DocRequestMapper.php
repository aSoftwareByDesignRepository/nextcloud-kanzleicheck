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

/** @template-extends QBMapper<DocRequest> */
class DocRequestMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_req', DocRequest::class);
	}

	public function find(int $id): ?DocRequest
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

	public function findForUpdate(int $id): ?DocRequest
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

	/** @return list<DocRequest> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<DocRequest>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** @return list<DocRequest> */
	public function findByOrg(int $orgId, string $state = '', int $limit = 200): array
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

	/** Idempotency lookup for recurrence — UNIQUE(template, org, period) backs it. */
	public function findByPeriod(int $templateId, int $orgId, string $periodKey): ?DocRequest
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('period_key', $qb->createNamedParameter($periodKey)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<DocRequest> open requests with a deadline — reminder scan page */
	public function findOpenWithDeadline(int $afterId, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNotNull('deadline'))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countOpenByOrg(int $orgId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('open')));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

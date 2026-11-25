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

/** @template-extends QBMapper<Task> */
class TaskMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'kzc_task', Task::class);
	}

	public function find(int $id): ?Task
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

	public function findForUpdate(int $id): ?Task
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

	/** @return list<Task> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Task>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** @return list<Task> board slice with filters */
	public function boardQuery(array $filters, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		if (!empty($filters['orgId'])) {
			$qb->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter((int)$filters['orgId'])));
		}
		if (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter((string)$filters['status'])));
		}
		if (!empty($filters['priority'])) {
			$qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter((string)$filters['priority'])));
		}
		if (!empty($filters['assignee'])) {
			$qb->andWhere($qb->expr()->eq('assignee', $qb->createNamedParameter((string)$filters['assignee'])));
		}
		$due = $filters['due'] ?? '';
		$today = new \DateTimeImmutable('today');
		if ($due === 'overdue') {
			$qb->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter($today, 'date')))
				->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('done')));
		} elseif ($due === '7d') {
			$qb->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($today->modify('+7 days'), 'date')));
		} elseif ($due === '30d') {
			$qb->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($today->modify('+30 days'), 'date')));
		} elseif ($due === 'none') {
			$qb->andWhere($qb->expr()->isNull('due_date'));
		}
		$qb->orderBy($qb->createFunction('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END'), 'ASC')
			->addOrderBy('due_date', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(500, $limit)));
		return $this->findEntities($qb);
	}

	/** @return list<Task> client-visible tasks of an org (portal_visibility = status) */
	public function findVisibleForOrg(int $orgId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('portal_visibility', $qb->createNamedParameter('status')))
			->orderBy('status', 'ASC')
			->addOrderBy('due_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function countOpenByOrgs(array $orgIds): int
	{
		// NC rejects >1000-expression IN lists for Oracle portability — chunk.
		$total = 0;
		foreach (array_chunk(array_map('intval', $orgIds), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))->from($this->getTableName())
				->where($qb->expr()->in('org_id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('done')));
			$total += (int)$qb->executeQuery()->fetchOne();
		}
		return $total;
	}
}

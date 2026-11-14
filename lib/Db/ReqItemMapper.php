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

/** @template-extends QBMapper<ReqItem> */
class ReqItemMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'kzc_req_item', ReqItem::class);
	}

	public function find(int $id): ?ReqItem
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

	public function findForUpdate(int $id): ?ReqItem
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

	/** @return list<ReqItem> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<ReqItem>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** @return list<ReqItem> ordered by position */
	public function findByReq(int $reqId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('req_id', $qb->createNamedParameter($reqId)))
			->orderBy('position', 'ASC');
		return $this->findEntities($qb);
	}

	/** Item counts per request: [req_id => [total, accepted]] */
	public function countsByReq(array $reqIds): array
	{
		if ($reqIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('req_id', 'state', $qb->func()->count('*', 'n'))->from($this->getTableName())
			->where($qb->expr()->in('req_id', $qb->createNamedParameter(array_map('intval', $reqIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('req_id', 'state');
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() ?: [] as $row) {
			$rid = (int)$row['req_id'];
			$out[$rid]['total'] = ($out[$rid]['total'] ?? 0) + (int)$row['n'];
			$out[$rid]['accepted'] = $out[$rid]['accepted'] ?? 0;
			if ($row['state'] === 'accepted') {
				$out[$rid]['accepted'] += (int)$row['n'];
			}
		}
		return $out;
	}
}

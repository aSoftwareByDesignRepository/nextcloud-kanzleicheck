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

/** @template-extends QBMapper<Org> */
class OrgMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'kzc_org', Org::class);
	}

	public function find(int $id): ?Org
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

	public function findForUpdate(int $id): ?Org
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

	/** @return list<Org> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Org>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	public function findByRef(string $ref): ?Org
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('ref', $qb->createNamedParameter($ref)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Org> */
	public function search(string $term = '', string $state = '', int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('name', 'ASC')
			->setMaxResults(max(1, min(500, $limit)))->setFirstResult(max(0, $offset));
		if ($term !== '') {
			$like = '%' . $this->db->escapeLikeParameter($term) . '%';
			$qb->where($qb->expr()->orX(
				$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('ref', $qb->createNamedParameter($like)),
			));
		}
		if ($state !== '') {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}
		return $this->findEntities($qb);
	}

	public function countAppEnabled(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('app_on', $qb->createNamedParameter(1)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<Org> */
	public function findAppEnabled(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('app_on', $qb->createNamedParameter(1)));
		return $this->findEntities($qb);
	}
}

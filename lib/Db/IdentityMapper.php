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

/** @template-extends QBMapper<Identity> */
class IdentityMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_identity', Identity::class);
	}

	public function find(int $id): ?Identity
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

	public function findForUpdate(int $id): ?Identity
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

	/** @return list<Identity> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Identity>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** Case-folded e-mail is the natural key — UNIQUE in the schema. */
	public function findByEmail(string $email): ?Identity
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('email', $qb->createNamedParameter(strtolower(trim($email)))));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** Identity bound to a given NC uid (attach binding), or null. */
	public function findByNcUid(string $ncUid): ?Identity
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('nc_uid', $qb->createNamedParameter($ncUid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Identity> */
	public function search(string $term, int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('display_name', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);
		if ($term !== '') {
			$like = '%' . $this->db->escapeLikeParameter($term) . '%';
			$qb->where($qb->expr()->orX(
				$qb->expr()->iLike('email', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('display_name', $qb->createNamedParameter($like)),
			));
		}
		return $this->findEntities($qb);
	}
}

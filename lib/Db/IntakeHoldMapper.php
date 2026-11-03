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

/** @template-extends QBMapper<IntakeHold> */
class IntakeHoldMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_intake_hold', IntakeHold::class);
	}

	public function find(int $id): ?IntakeHold
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

	public function findForUpdate(int $id): ?IntakeHold
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

	/** @return list<IntakeHold> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<IntakeHold>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** @return list<IntakeHold> */
	public function findHeld(int $orgId = 0, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('held')))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		if ($orgId > 0) {
			$qb->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		}
		return $this->findEntities($qb);
	}

	public function existsByMsgHash(string $msgHash): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('msg_hash', $qb->createNamedParameter($msgHash)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	public function deleteBefore(\DateTimeImmutable $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('received_at', $qb->createNamedParameter($cutoff, 'datetime')));
		return $qb->executeStatement();
	}
}

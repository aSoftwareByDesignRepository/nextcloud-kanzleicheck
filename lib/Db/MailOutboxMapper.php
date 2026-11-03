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

/** @template-extends QBMapper<MailOutbox> */
class MailOutboxMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_mail_outbox', MailOutbox::class);
	}

	public function find(int $id): ?MailOutbox
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

	public function findForUpdate(int $id): ?MailOutbox
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

	/** @return list<MailOutbox> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<MailOutbox>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	public function findByIdemKey(string $idemKey): ?MailOutbox
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** Row-claim CAS: only one drainer wins a pending row (§9.9). */
	public function claim(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter('sending'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('pending')));
		return $qb->executeStatement() === 1;
	}

	/** @return list<MailOutbox> due pending rows, oldest first */
	public function dueBatch(\DateTimeImmutable $now, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('next_try_at', $qb->createNamedParameter($now, 'datetime')))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, min(200, $limit)));
		return $this->findEntities($qb);
	}

	public function pendingCount(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('pending')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function failedCount(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('failed')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function deleteSentBefore(\DateTimeImmutable $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('sent')))
			->andWhere($qb->expr()->lt('sent_at', $qb->createNamedParameter($cutoff, 'datetime')));
		return $qb->executeStatement();
	}

	public function deleteFailedBefore(\DateTimeImmutable $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('failed')))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
		return $qb->executeStatement();
	}
}

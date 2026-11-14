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

/** @template-extends QBMapper<Otp> */
class OtpMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'kzc_otp', Otp::class);
	}

	public function find(int $id): ?Otp
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

	public function findForUpdate(int $id): ?Otp
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

	/** @return list<Otp> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Otp>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	/** Active (unused, unexpired) code for (email, purpose) — at most one by design. */
	public function findActive(string $email, string $purpose): ?Otp
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('email', $qb->createNamedParameter(strtolower(trim($email)))))
			->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter($purpose)))
			->andWhere($qb->expr()->isNull('used_at'))
			->andWhere($qb->expr()->gte('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** Invalidate every unused code for (email, purpose) — single-active invariant. */
	public function invalidateActive(string $email, string $purpose): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('used_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('email', $qb->createNamedParameter(strtolower(trim($email)))))
			->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter($purpose)))
			->andWhere($qb->expr()->isNull('used_at'));
		$qb->executeStatement();
	}

	public function findByMagicHash(string $magicHash): ?Otp
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('magic_hash', $qb->createNamedParameter($magicHash)))
			->andWhere($qb->expr()->isNull('used_at'))
			->andWhere($qb->expr()->gte('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** Recent OTP rows for an e-mail — used by the 5/h send cap (count only, no PII out). */
	public function countRecentForEmail(string $email, \DateTimeImmutable $since): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('email', $qb->createNamedParameter(strtolower(trim($email)))))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function deleteOlderThan(\DateTimeImmutable $cutoff, int $limit = 500): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
		return $qb->executeStatement();
	}
}

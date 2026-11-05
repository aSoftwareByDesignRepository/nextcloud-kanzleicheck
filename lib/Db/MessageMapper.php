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

/** @template-extends QBMapper<Message> */
class MessageMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'ptl_message', Message::class);
	}

	public function find(int $id): ?Message
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

	public function findForUpdate(int $id): ?Message
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

	/** @return list<Message> */
	/**
	 * @param 'ASC'|'DESC' $dir
	 * @return list<Message>
	 */
	public function findAllOrdered(string $orderCol = 'id', string $dir = 'ASC'): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy($orderCol, $dir);
		return $this->findEntities($qb);
	}

	public function findByDedupe(int $convId, string $author, string $clientUuid): ?Message
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->andWhere($qb->expr()->eq('author', $qb->createNamedParameter($author)))
			->andWhere($qb->expr()->eq('client_uuid', $qb->createNamedParameter($clientUuid)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Message> ascending from (afterId, limit≤100] */
	public function listAfter(int $convId, int $afterId, int $limit = 100): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId)))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, min(100, $limit)));
		return $this->findEntities($qb);
	}

	/** @return list<Message> newest-first page */
	public function listPage(int $convId, int $limit = 50, int $beforeId = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->orderBy('id', 'DESC')
			->setMaxResults(max(1, min(100, $limit)));
		if ($beforeId > 0) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId)));
		}
		return $this->findEntities($qb);
	}

	public function countAfter(int $convId, int $afterId, string $excludeAuthor = ''): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId)));
		if ($excludeAuthor !== '') {
			$qb->andWhere($qb->expr()->neq('author', $qb->createNamedParameter($excludeAuthor)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Recent sends by one actor — 30 msg/min client rate limit. */
	public function countRecentByAuthor(int $convId, string $author, \DateTimeImmutable $since): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->andWhere($qb->expr()->eq('author', $qb->createNamedParameter($author)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, 'datetime')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	// unique-conflict:ok author IS part of ptl_msg_dedupe_uq — but client_uuid
	// is nulled on the same write, so merged-author rows carry (conv, author,
	// NULL) keys that MySQL treats as distinct; no collision is reachable.
	public function anonymizeAuthor(string $actor, string $replacement): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('author', $qb->createNamedParameter($replacement))
			->set('client_uuid', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('author', $qb->createNamedParameter($actor)));
		return $qb->executeStatement();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\IDBConnection;

/** `ptl_read` — per-actor last-read message id per conversation. */
class ReadMarkerMapper
{
	public const TABLE = 'ptl_read';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	public function get(int $convId, string $actor): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('last_read_id')->from(self::TABLE)
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->andWhere($qb->expr()->eq('actor', $qb->createNamedParameter($actor)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? 0 : (int)$v;
	}

	/** Monotonic advance — a rewind write is ignored (last_read_id only grows). */
	public function advance(int $convId, string $actor, int $lastReadId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('last_read_id', $qb->createNamedParameter($lastReadId))
			->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId)))
			->andWhere($qb->expr()->eq('actor', $qb->createNamedParameter($actor)))
			->andWhere($qb->expr()->lt('last_read_id', $qb->createNamedParameter($lastReadId)));
		if ($qb->executeStatement() === 0) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert(self::TABLE)->values([
				'conv_id' => $ins->createNamedParameter($convId),
				'actor' => $ins->createNamedParameter($actor),
				'last_read_id' => $ins->createNamedParameter($lastReadId),
			]);
			try {
				$ins->executeStatement();
			} catch (\Throwable) {
				// raced insert — retry monotonic update
				$qb->executeStatement();
			}
		}
	}
}

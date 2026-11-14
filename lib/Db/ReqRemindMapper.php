<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\IDBConnection;

/** `kzc_req_remind` — ZF-03 reminder idempotency: row inserted BEFORE the outbox row. */
class ReqRemindMapper
{
	public const TABLE = 'kzc_req_remind';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** True when this run claimed the reminder slot for (req, date). */
	public function claim(int $reqId, string $remindOnYmd): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'req_id' => $qb->createNamedParameter($reqId),
			'remind_on' => $qb->createNamedParameter($remindOnYmd),
		]);
		try {
			$qb->executeStatement();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function countFor(int $reqId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from(self::TABLE)
			->where($qb->expr()->eq('req_id', $qb->createNamedParameter($reqId)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

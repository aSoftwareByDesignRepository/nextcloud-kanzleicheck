<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\IDBConnection;

/** `kzc_job_cursor` — ZF-06 persisted cursors for time-budgeted jobs. */
class JobCursorMapper
{
	public const TABLE = 'kzc_job_cursor';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	public function get(string $job): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('cursor')->from(self::TABLE)
			->where($qb->expr()->eq('job', $qb->createNamedParameter($job)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? '' : (string)$v;
	}

	public function set(string $job, string $cursor): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('cursor', $qb->createNamedParameter($cursor))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('job', $qb->createNamedParameter($job)));
		if ($qb->executeStatement() === 0) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert(self::TABLE)->values([
				'job' => $ins->createNamedParameter($job),
				'cursor' => $ins->createNamedParameter($cursor),
				'updated_at' => $ins->createNamedParameter(new \DateTime(), 'datetime'),
			]);
			try {
				$ins->executeStatement();
			} catch (\Throwable $e) {
				\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('kanzleicheck job cursor insert failed', ['job' => $job, 'e' => $e->getMessage()]);
				return;
			}
		}
	}

	/** Last successful run timestamp of a job (for the >2h cron banner). */
	public function lastRun(string $job): ?\DateTimeImmutable
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('updated_at')->from(self::TABLE)
			->where($qb->expr()->eq('job', $qb->createNamedParameter($job)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? null : new \DateTimeImmutable((string)$v);
	}
}

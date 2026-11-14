<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\IDBConnection;

/** `kzc_intake` — per-org intake address state (PK org_id). */
class IntakeMapper
{
	public const TABLE = 'kzc_intake';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return array{enabled:int,token_hash:string,rotated_at:string}|null */
	public function find(int $orgId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : [
			'enabled' => (int)$row['enabled'],
			'token_hash' => (string)$row['token_hash'],
			'rotated_at' => (string)$row['rotated_at'],
		];
	}

	public function upsert(int $orgId, bool $enabled, string $tokenHash): void
	{
		$existing = $this->find($orgId);
		if ($existing === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'org_id' => $qb->createNamedParameter($orgId),
				'enabled' => $qb->createNamedParameter($enabled ? 1 : 0),
				'token_hash' => $qb->createNamedParameter($tokenHash),
				'rotated_at' => $qb->createNamedParameter(new \DateTime(), 'datetime'),
			]);
			$qb->executeStatement();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('enabled', $qb->createNamedParameter($enabled ? 1 : 0))
			->set('token_hash', $qb->createNamedParameter($tokenHash))
			->set('rotated_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}

	public function setEnabled(int $orgId, bool $enabled): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('enabled', $qb->createNamedParameter($enabled ? 1 : 0))
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}
}

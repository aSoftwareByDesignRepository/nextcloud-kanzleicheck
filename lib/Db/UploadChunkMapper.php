<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\IDBConnection;

/** `ptl_upload_chunk` — ZF-05 chunk upload sessions (PK upload_id string). */
class UploadChunkMapper
{
	public const TABLE = 'ptl_upload_chunk';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return array<string,mixed>|null */
	public function find(string $uploadId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	public function create(string $uploadId, int $orgId, string $actor, string $fileName, int $totalBytes): void
	{
		$now = new \DateTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'upload_id' => $qb->createNamedParameter($uploadId),
			'org_id' => $qb->createNamedParameter($orgId),
			'actor' => $qb->createNamedParameter($actor),
			'file_name' => $qb->createNamedParameter($fileName),
			'total_bytes' => $qb->createNamedParameter($totalBytes),
			'received_bytes' => $qb->createNamedParameter(0),
			'chunk_count' => $qb->createNamedParameter(0),
			'state' => $qb->createNamedParameter('receiving'),
			'created_at' => $qb->createNamedParameter($now, 'datetime'),
			'last_chunk_at' => $qb->createNamedParameter($now, 'datetime'),
		]);
		$qb->executeStatement();
	}

	public function addChunk(string $uploadId, int $bytes): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('received_bytes', $qb->createFunction('received_bytes + ' . (int)$bytes))
			->set('chunk_count', $qb->createFunction('chunk_count + 1'))
			->set('last_chunk_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('receiving')));
		$qb->executeStatement();
	}

	/** CAS receiving→assembling (ZF-05 finish idempotency). True for the winner. */
	public function claimAssembly(string $uploadId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter('assembling'))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('receiving')));
		return $qb->executeStatement() === 1;
	}

	public function setState(string $uploadId, string $state): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter($state))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));
		$qb->executeStatement();
	}

	/** @return list<array{upload_id:string}> sessions idle past $cutoff (still receiving/assembling) */
	public function findIdle(\DateTimeImmutable $cutoff, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('upload_id')->from(self::TABLE)
			->where($qb->expr()->in('state', $qb->createNamedParameter(['receiving', 'assembling'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('last_chunk_at', $qb->createNamedParameter($cutoff, 'datetime')))
			->setMaxResults($limit);
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() ?: [] as $row) {
			$out[] = ['upload_id' => (string)$row['upload_id']];
		}
		return $out;
	}

	public function delete(string $uploadId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));
		$qb->executeStatement();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\IDBConnection;

/** `ptl_req_file` — uploads attached to request items. */
class ReqFileMapper
{
	public const TABLE = 'ptl_req_file';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	public function attach(int $itemId, int $uploadId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'item_id' => $qb->createNamedParameter($itemId),
			'upload_id' => $qb->createNamedParameter($uploadId),
		]);
		try {
			$qb->executeStatement();
		} catch (\Throwable $e) {
			// already attached — idempotent, but log so unexpected failures stay observable
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->info('portalcheck req-file attach skipped', ['item' => $itemId, 'upload' => $uploadId, 'e' => $e->getMessage()]);
			return;
		}
	}

	/** @return list<int> */
	public function uploadIdsForItem(int $itemId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('upload_id')->from(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) ?: []);
	}
}

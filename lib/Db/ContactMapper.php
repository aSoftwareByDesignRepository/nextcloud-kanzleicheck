<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\IDBConnection;

/** `ptl_contact` — responsible staff per org (contact card + notification routing). */
class ContactMapper
{
	public const TABLE = 'ptl_contact';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return list<array{user_id:string,role_label:?string,show_phone:int,show_email:int,position:int}> */
	public function findForOrg(int $orgId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'role_label', 'show_phone', 'show_email', 'position')
			->from(self::TABLE)
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->orderBy('position', 'ASC');
		return $qb->executeQuery()->fetchAll() ?: [];
	}

	/** @param list<array{userId:string,roleLabel:?string,showPhone:bool,showEmail:bool}> $contacts ≤5 */
	public function setForOrg(int $orgId, array $contacts): void
	{
		$del = $this->db->getQueryBuilder();
		$del->delete(self::TABLE)->where($del->expr()->eq('org_id', $del->createNamedParameter($orgId)));
		$del->executeStatement();
		$pos = 0;
		foreach ($contacts as $c) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'org_id' => $qb->createNamedParameter($orgId),
				'user_id' => $qb->createNamedParameter((string)$c['userId']),
				'role_label' => $qb->createNamedParameter($c['roleLabel'] ?? null),
				'show_phone' => $qb->createNamedParameter(!empty($c['showPhone']) ? 1 : 0),
				'show_email' => $qb->createNamedParameter(!isset($c['showEmail']) || $c['showEmail'] ? 1 : 0),
				'position' => $qb->createNamedParameter($pos++),
			]);
			$qb->executeStatement();
		}
	}

	/** @return list<int> org ids where this user is responsible */
	public function orgsForUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('org_id')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) ?: []);
	}
}

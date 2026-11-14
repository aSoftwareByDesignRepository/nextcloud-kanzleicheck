<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\IDBConnection;

/** `kzc_req_tpl_org` — orgs a recurring template targets. */
class ReqTplOrgMapper
{
	public const TABLE = 'kzc_req_tpl_org';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	// unique-conflict:ok kzc_req_tpl_org carries no unique index — replace-set
	// (delete + insert by template_id) cannot violate a constraint.
	/** @param list<int> $orgIds */
	public function setOrgs(int $templateId, array $orgIds): void
	{
		$del = $this->db->getQueryBuilder();
		$del->delete(self::TABLE)->where($del->expr()->eq('template_id', $del->createNamedParameter($templateId)));
		$del->executeStatement();
		foreach (array_unique($orgIds) as $orgId) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'template_id' => $qb->createNamedParameter($templateId),
				'org_id' => $qb->createNamedParameter((int)$orgId),
			]);
			$qb->executeStatement();
		}
	}

	/** @return list<int> */
	public function orgIdsFor(int $templateId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('org_id')->from(self::TABLE)
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) ?: []);
	}

	/** @return list<int> template ids targeting this org */
	public function templateIdsForOrg(int $orgId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('template_id')->from(self::TABLE)
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) ?: []);
	}

	public function deleteForOrg(int $orgId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\IDBConnection;

/** `ptl_notify` — composite PK (identity_id, org_id), mail throttle + opt-out. */
class NotifyMapper
{
	public const TABLE = 'ptl_notify';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return array{mails_enabled:int,last_mail_at:?string,pending_kinds:?string}|null */
	public function find(int $identityId, int $orgId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : [
			'mails_enabled' => (int)$row['mails_enabled'],
			'last_mail_at' => $row['last_mail_at'] === null ? null : (string)$row['last_mail_at'],
			'pending_kinds' => $row['pending_kinds'] === null ? null : (string)$row['pending_kinds'],
		];
	}

	/** Insert row if missing (mails_enabled default true), else no-op. */
	public function ensure(int $identityId, int $orgId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('identity_id')->from(self::TABLE)
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		if ($qb->executeQuery()->fetch() !== false) {
			return;
		}
		$ins = $this->db->getQueryBuilder();
		$ins->insert(self::TABLE)->values([
			'identity_id' => $ins->createNamedParameter($identityId),
			'org_id' => $ins->createNamedParameter($orgId),
			'mails_enabled' => $ins->createNamedParameter(1),
		]);
		try {
			$ins->executeStatement();
		} catch (\Throwable $e) {
			// concurrent insert won — fine, but keep observable
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->info('portalcheck notify-pref insert skipped', ['identity' => $identityId, 'org' => $orgId, 'e' => $e->getMessage()]);
			return;
		}
	}

	public function setMailsEnabled(int $identityId, int $orgId, bool $enabled): void
	{
		$this->ensure($identityId, $orgId);
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('mails_enabled', $qb->createNamedParameter($enabled ? 1 : 0))
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}

	/**
	 * Throttle CAS: claim the 15-min send slot. Returns true when this call
	 * may send a mail now; false when it must batch the kind instead.
	 */
	public function claimMailSlot(int $identityId, int $orgId, int $throttleSeconds): bool
	{
		$this->ensure($identityId, $orgId);
		$cutoff = (new \DateTimeImmutable())->modify('-' . $throttleSeconds . ' seconds');
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('last_mail_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->set('pending_kinds', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('mails_enabled', $qb->createNamedParameter(1)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('last_mail_at'),
					$qb->expr()->lt('last_mail_at', $qb->createNamedParameter($cutoff, 'datetime'))
				)
			);
		return $qb->executeStatement() === 1;
	}

	/** Batch a kind into pending_kinds (comma list, deduped). */
	public function addPendingKind(int $identityId, int $orgId, string $kind): void
	{
		$this->ensure($identityId, $orgId);
		$row = $this->find($identityId, $orgId);
		$kinds = $row !== null && $row['pending_kinds'] !== null && $row['pending_kinds'] !== ''
			? explode(',', (string)$row['pending_kinds']) : [];
		if (!in_array($kind, $kinds, true)) {
			$kinds[] = $kind;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('pending_kinds', $qb->createNamedParameter(implode(',', $kinds)))
			->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}

	/** @return list<array{identity_id:int,org_id:int,pending_kinds:string}> rows with batched kinds past the throttle window */
	public function findFlushable(int $throttleSeconds, int $limit = 200): array
	{
		$cutoff = (new \DateTimeImmutable())->modify('-' . $throttleSeconds . ' seconds');
		$qb = $this->db->getQueryBuilder();
		$qb->select('identity_id', 'org_id', 'pending_kinds')->from(self::TABLE)
			->where($qb->expr()->isNotNull('pending_kinds'))
			->andWhere($qb->expr()->eq('mails_enabled', $qb->createNamedParameter(1)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('last_mail_at'),
					$qb->expr()->lt('last_mail_at', $qb->createNamedParameter($cutoff, 'datetime'))
				)
			)
			->setMaxResults($limit);
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() ?: [] as $row) {
			$out[] = [
				'identity_id' => (int)$row['identity_id'],
				'org_id' => (int)$row['org_id'],
				'pending_kinds' => (string)$row['pending_kinds'],
			];
		}
		return $out;
	}

	public function deleteForIdentity(int $identityId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
		$qb->executeStatement();
	}
}

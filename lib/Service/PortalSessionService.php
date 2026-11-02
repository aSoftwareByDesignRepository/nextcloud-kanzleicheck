<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copied from bookingcheck@31c2669e70 lib/Service/PortalSessionService.php
 * (COPIED-SOURCES.md). PortalCheck changes: sessions bind to identity_id
 * (not raw email) so erasure/disabling propagates; resolve() returns the
 * row, the caller checks identity status.
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\PortalSession;
use OCA\PortalCheck\Db\PortalSessionMapper;
use OCP\IDBConnection;

/**
 * Portal web sessions: 256-bit bearer token in an httponly cookie, hash at
 * rest, 30 d sliding expiry (admin-configurable), 365 d absolute cap.
 */
class PortalSessionService
{
	private const ABS_CAP_DAYS = 365;

	public function __construct(
		private readonly PortalSessionMapper $sessions,
		private readonly TokenService $tokens,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
	) {
	}

	/** @return array{0:string,1:PortalSession} plaintext token + row */
	public function create(int $identityId, string $ua): array
	{
		[$token, $hash] = $this->tokens->mint();
		$now = new \DateTimeImmutable();
		$days = min($this->settings->getInt('sess_days', 30), self::ABS_CAP_DAYS);
		$days = max(1, $days);
		$s = new PortalSession();
		$s->setTokenHash($hash);
		$s->setIdentityId($identityId);
		$s->setCreatedAt($now);
		$s->setExpiresAt($now->modify('+' . $days . ' days'));
		$s->setAbsExpiresAt($now->modify('+' . self::ABS_CAP_DAYS . ' days'));
		$s->setLastSeenAt($now);
		$s->setUaShort(mb_substr($ua, 0, 190));
		$this->sessions->insert($s);
		return [$token, $s];
	}

	/**
	 * Resolve a presented cookie token → session row or null.
	 * Sliding renewal extends expires_at, never beyond abs_expires_at.
	 */
	public function resolve(string $token): ?PortalSession
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$row = $this->sessions->findByTokenHash($this->tokens->hash($token));
		if ($row === null || $row->getRevokedAt() !== null) {
			return null;
		}
		$now = new \DateTimeImmutable();
		if ($row->getAbsExpiresAt() !== null && $row->getAbsExpiresAt() < $now) {
			return null;
		}
		if ($row->getExpiresAt() !== null && $row->getExpiresAt() < $now) {
			return null;
		}
		$days = max(1, min($this->settings->getInt('sess_days', 30), self::ABS_CAP_DAYS));
		$newExp = $now->modify('+' . $days . ' days');
		if ($row->getAbsExpiresAt() !== null && $newExp > $row->getAbsExpiresAt()) {
			$newExp = $row->getAbsExpiresAt();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_session')
			->set('expires_at', $qb->createNamedParameter($newExp, 'datetime'))
			->set('last_seen_at', $qb->createNamedParameter($now, 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())));
		$qb->executeStatement();
		return $row;
	}

	public function revoke(string $token): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_session')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($this->tokens->hash($token))));
		$qb->executeStatement();
	}

	public function revokeAllForIdentity(int $identityId): int
	{
		return $this->sessions->revokeForIdentity($identityId);
	}
}

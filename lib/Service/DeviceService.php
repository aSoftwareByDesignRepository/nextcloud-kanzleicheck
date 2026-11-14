<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\Device;
use OCA\KanzleiCheck\Db\DeviceMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Companion device tokens: 256-bit, hash at rest, 365 d absolute expiry,
 * renewed (last_used_at) on every authenticated call, revocable per device
 * or per identity. Cap: 50 devices per identity — the oldest unused is
 * auto-revoked (the owner gets a new-device mail on pairing anyway).
 */
class DeviceService
{
	public const MAX_DEVICES = 50;

	public function __construct(
		private readonly DeviceMapper $devices,
		private readonly TokenService $tokens,
		private readonly IDBConnection $db,
	) {
	}

	/** @return array{0:string,1:Device} plaintext token (returned once) + row */
	public function pair(int $identityId, string $deviceName, string $platform): array
	{
		if (!in_array($platform, ['android', 'ios'], true)) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'invalid platform', ['platform' => 'invalid']);
		}
		[$token, $hash] = $this->tokens->mint();
		$now = new \DateTimeImmutable();
		$d = new Device();
		$d->setIdentityId($identityId);
		$d->setTokenHash($hash);
		$d->setDeviceName(mb_substr(trim($deviceName) !== '' ? $deviceName : 'device', 0, 100));
		$d->setPlatform($platform);
		$d->setCreatedAt($now);
		$d->setLastUsedAt($now);
		$d->setAbsExpiresAt($now->modify('+365 days'));
		for ($attempt = 0; ; $attempt++) {
			try {
				$this->devices->insert($d);
				break;
			} catch (DbException $e) {
				// token_hash collision — remint and retry (astronomically rare, but never a 500)
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
				[$token, $hash] = $this->tokens->mint();
				$d->setTokenHash($hash);
			}
		}
		// cap: revoke the oldest-unused beyond the limit (US-P02 / §6)
		$this->devices->revokeOldestBeyond($identityId, self::MAX_DEVICES);
		return [$token, $d];
	}

	/**
	 * Resolve a presented device token → row or null.
	 * Touch last_used_at (renewal) — identity status is checked per request
	 * by the caller (identity disabled mid-flight must cut off).
	 */
	public function resolve(string $token): ?Device
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$row = $this->devices->findByTokenHash($this->tokens->hash($token));
		if ($row === null || $row->getRevokedAt() !== null) {
			return null;
		}
		$now = new \DateTimeImmutable();
		if ($row->getAbsExpiresAt() !== null && $row->getAbsExpiresAt() < $now) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('kzc_device')
			->set('last_used_at', $qb->createNamedParameter($now, 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())));
		$qb->executeStatement();
		return $row;
	}

	public function revoke(int $deviceId, int $identityId = 0): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('kzc_device')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($deviceId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		if ($identityId > 0) {
			$qb->andWhere($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)));
		}
		return $qb->executeStatement() === 1;
	}
}

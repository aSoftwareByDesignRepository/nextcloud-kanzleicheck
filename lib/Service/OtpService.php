<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copied from bookingcheck@31c2669e70 lib/Service/OtpService.php
 * (COPIED-SOURCES.md). PortalCheck changes:
 *  - purpose binding (AF-08): every OTP is scoped to 'login', 'pair' or
 *    'approve:<id>' — a code for one purpose can never satisfy another.
 *  - verify() returns the Otp row id on success (approvals bind otp_id).
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\Otp;
use OCA\PortalCheck\Db\OtpMapper;
use OCP\IDBConnection;

/**
 * Passwordless portal codes.
 *
 * 6 digits, random_int, SHA-256 at rest, TTL per settings (default 10 min,
 * admin 5–30), ≤5 verify attempts, single active code per (email, purpose).
 * The SQL-fenced attempt increment happens BEFORE the compare, so a parallel
 * burst cannot exceed the cap; used_at CAS gives exactly-once consumption.
 */
class OtpService
{
	public const MAX_ATTEMPTS = 5;
	public const PURPOSE_LOGIN = 'login';
	public const PURPOSE_PAIR = 'pair';

	public function __construct(
		private readonly OtpMapper $otps,
		private readonly TokenService $tokens,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
	) {
	}

	public static function approvalPurpose(int $approvalId): string
	{
		return 'approve:' . $approvalId;
	}

	/**
	 * Issue a fresh code (+ magic token for login). Invalidates any prior
	 * unused code for the same (email, purpose).
	 * Returns [code, magicToken|null, otpId] — caller mails it, never stores.
	 * @return array{0:string,1:?string,2:int}
	 */
	public function issue(string $email, string $purpose, string $ip, bool $withMagic): array
	{
		$email = strtolower(trim($email));
		$code = $this->tokens->mintOtp();
		[$magicToken, $magicHash] = $withMagic ? $this->tokens->mint() : [null, null];
		$ttl = min(30, max(5, $this->settings->getInt('otp_ttl_min', 10)));
		$now = new \DateTimeImmutable();

		$this->db->beginTransaction();
		try {
			$this->otps->invalidateActive($email, $purpose);
			$o = new Otp();
			$o->setEmail($email);
			$o->setPurpose($purpose);
			$o->setCodeHash($this->tokens->hash($code));
			$o->setMagicHash($magicHash);
			$o->setAttempts(0);
			$o->setCreatedAt($now);
			$o->setExpiresAt($now->modify('+' . $ttl . ' minutes'));
			$o->setIpHash(hash('sha256', $ip));
			$inserted = $this->otps->insert($o);
			$this->db->commit();
			return [$code, $magicToken, (int)$inserted->getId()];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Verify a presented code for a purpose. Consumes on success (single use)
	 * and counts attempts BEFORE comparing (parallel-burst safe).
	 * Returns the consumed row id on success, null on any miss — callers
	 * must render one uniform INVALID_CODE.
	 */
	public function verify(string $email, string $purpose, string $code): ?int
	{
		$row = $this->otps->findActive(strtolower(trim($email)), $purpose);
		if ($row === null) {
			return null;
		}
		if ((int)$row->getAttempts() >= self::MAX_ATTEMPTS) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_otp')->set('attempts', $qb->createFunction('attempts + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(self::MAX_ATTEMPTS)))
			->andWhere($qb->expr()->isNull('used_at'));
		if ($qb->executeStatement() === 0) {
			return null;
		}
		if (!$this->tokens->verify($code, $row->getCodeHash())) {
			return null;
		}
		return $this->consume((int)$row->getId()) ? (int)$row->getId() : null;
	}

	/** Magic-link consume: single use, hashed lookup, purpose-checked by caller. */
	public function consumeMagic(string $token): ?Otp
	{
		$row = $this->otps->findByMagicHash($this->tokens->hash($token));
		if ($row === null) {
			return null;
		}
		return $this->consume((int)$row->getId()) ? $row : null;
	}

	private function consume(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_otp')->set('used_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNull('used_at'));
		return $qb->executeStatement() === 1;
	}
}

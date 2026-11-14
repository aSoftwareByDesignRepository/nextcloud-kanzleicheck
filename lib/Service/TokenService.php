<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copied from bookingcheck@31c2669e70 lib/Service/TokenService.php
 * (COPIED-SOURCES.md). Adjusted: app namespace only.
 */

namespace OCA\KanzleiCheck\Service;

/**
 * Bearer-token mint/verify.
 *
 * Invariant: only SHA-256 hashes of tokens are ever persisted. The plaintext
 * exists exactly once — in the URL/email/cookie we hand to its owner.
 * Verification is a constant-time hash compare.
 */
class TokenService
{
	/** @return array{0:string,1:string} [plaintext token, sha256 hash] */
	public function mint(int $bytes = 32): array
	{
		$token = rtrim(strtr(base64_encode(random_bytes(max(1, $bytes))), '+/', '-_'), '=');
		return [$token, $this->hash($token)];
	}

	public function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	public function verify(string $presentedToken, string $storedHash): bool
	{
		return hash_equals($storedHash, $this->hash($presentedToken));
	}

	/** Human-typable OTP code; random_int only (never mt_rand). */
	public function mintOtp(): string
	{
		return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	}

	/** HMAC token for intake addresses: 16 chars of an org-bound HMAC. */
	public function intakeToken(string $secret, int $orgId): string
	{
		return substr(hash_hmac('sha256', 'kzc-intake:' . $orgId, $secret), 0, 16);
	}
}

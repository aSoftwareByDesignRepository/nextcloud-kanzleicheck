<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

/**
 * Double-submit CSRF for portal client routes (AF-02).
 *
 * The session cookie carries ambient authority on public POSTs, so every
 * mutation must prove the caller read a token bound to that session:
 * we issue `kzc_csrf` (JS-readable, SameSite=Lax) whose value is
 * HMAC(sessionToken, 'kzc-csrf'). The client sends it back in
 * `X-KZC-CSRF` (fetch) or the hidden `kzc_csrf` form field.
 * A cross-site attacker can't read the session cookie or the token —
 * SameSite=Lax already blocks POST sending of cookies, the token is the
 * second factor for same-site subtleties + non-browser clients.
 */
class CsrfService
{
	public const COOKIE_NAME = 'kzc_csrf';
	public const HEADER = 'X-KZC-CSRF';

	public function tokenFor(string $sessionToken): string
	{
		return hash_hmac('sha256', 'kzc-csrf', $sessionToken);
	}

	public function validate(string $sessionToken, string $presented): bool
	{
		if ($presented === '' || strlen($presented) > 128) {
			return false;
		}
		return hash_equals($this->tokenFor($sessionToken), $presented);
	}
}

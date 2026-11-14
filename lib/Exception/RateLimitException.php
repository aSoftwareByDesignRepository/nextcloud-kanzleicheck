<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Exception;

/** 429 RATE_LIMITED — response carries Retry-After. */
class RateLimitException extends \RuntimeException
{
	public function __construct(private readonly int $retryAfter = 60)
	{
		parent::__construct('RATE_LIMITED');
	}

	public function getRetryAfter(): int
	{
		return $this->retryAfter;
	}
}

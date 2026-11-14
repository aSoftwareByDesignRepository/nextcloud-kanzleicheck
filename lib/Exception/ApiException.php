<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Exception;

/** Status-coded domain failure (FILE_TOO_LARGE, NO_PORTAL_SLOT, …). */
class ApiException extends \RuntimeException
{
	public function __construct(
		private readonly int $status,
		private readonly string $errorCode,
		string $message = '',
		private readonly array $fields = [],
		private readonly ?int $retryAfter = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getStatus(): int
	{
		return $this->status;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function getFields(): array
	{
		return $this->fields;
	}

	public function getRetryAfter(): ?int
	{
		return $this->retryAfter;
	}
}

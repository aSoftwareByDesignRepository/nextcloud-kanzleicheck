<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Exception;

/** Stale revision / concurrent write — 409 CONFLICT, client reloads the item. */
class ConflictException extends \RuntimeException
{
	public function __construct(string $message = 'CONFLICT', private readonly ?array $current = null)
	{
		parent::__construct($message);
	}

	public function getCurrent(): ?array
	{
		return $this->current;
	}
}

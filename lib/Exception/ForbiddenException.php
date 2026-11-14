<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Exception;

/** 403 — authenticated but outside the access door / wrong role. */
class ForbiddenException extends \RuntimeException
{
	public function __construct(string $message = 'FORBIDDEN')
	{
		parent::__construct($message);
	}
}

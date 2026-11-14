<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Exception;

/**
 * Uniform miss — covers "doesn't exist" AND "exists but not yours" (G-01).
 * The wire payload is identical either way; nothing distinguishes a
 * foreign-org id from a never-issued id.
 */
class NotFoundException extends \RuntimeException
{
	public function __construct(string $message = 'not found')
	{
		parent::__construct($message);
	}
}

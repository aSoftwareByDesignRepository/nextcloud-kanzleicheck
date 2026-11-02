<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Exception;

/** 401 — dead session, revoked device, missing credential. */
class UnauthorizedException extends \RuntimeException
{
	public function __construct(string $message = 'UNAUTHORIZED')
	{
		parent::__construct($message);
	}
}

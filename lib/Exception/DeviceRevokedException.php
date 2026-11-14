<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Exception;

/** 401 DEVICE_REVOKED — the app wipes cached data and returns to login. */
class DeviceRevokedException extends UnauthorizedException
{
	public function __construct()
	{
		parent::__construct('DEVICE_REVOKED');
	}
}

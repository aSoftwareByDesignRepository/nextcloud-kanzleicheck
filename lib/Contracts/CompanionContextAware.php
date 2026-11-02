<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Contracts;

use OCA\PortalCheck\Db\Device;
use OCA\PortalCheck\Db\Identity;

/** Middleware → controller context contract (typed, no dynamic props). */
interface CompanionContextAware
{
	public function setCompanionContext(Identity $identity, Device $device): void;
}

<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Contracts;

use OCA\KanzleiCheck\Db\Device;
use OCA\KanzleiCheck\Db\Identity;

/** Middleware → controller context contract (typed, no dynamic props). */
interface CompanionContextAware
{
	public function setCompanionContext(Identity $identity, Device $device): void;
}

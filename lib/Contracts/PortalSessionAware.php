<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Contracts;

use OCA\PortalCheck\Db\Identity;

/** Middleware → controller context contract (typed, no dynamic props). */
interface PortalSessionAware
{
	public function setPortalIdentity(Identity $identity, string $sessionToken): void;
}

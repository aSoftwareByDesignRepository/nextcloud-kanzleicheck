<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Contracts;

/** Middleware → controller context contract (typed, no dynamic props). */
interface StaffContextAware
{
	public function setStaffContext(string $uid, bool $isAppAdmin): void;
}

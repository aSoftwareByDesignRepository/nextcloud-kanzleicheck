<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\AuditMapper;

/** Audit trail (§6 observability): actor, action, target, JSON detail without PII. */
class AuditService
{
	public function __construct(private readonly AuditMapper $audits)
	{
	}

	public function log(string $actor, string $action, string $targetType, string|int $targetId, ?array $detail = null): void
	{
		$this->audits->add($actor, $action, $targetType, (string)$targetId, $detail);
	}
}

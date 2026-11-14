<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\UploadLinkMapper;
use OCP\IUserManager;

/**
 * Actor string ↔ display name resolution (SPEC §9.1).
 *
 * `portal:<id>` client identities (':' is illegal in NC uids → no collision),
 * `ulink:<id>` third-party upload-link actors, `system:<job>` automation,
 * anything else is an NC uid resolved via IUserManager.
 */
class ActorResolver
{
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IdentityMapper $identities,
		private readonly UploadLinkMapper $ulinks,
	) {
	}

	public static function portal(int $identityId): string
	{
		return 'portal:' . $identityId;
	}

	public static function ulink(int $linkId): string
	{
		return 'ulink:' . $linkId;
	}

	public static function system(string $job): string
	{
		return 'system:' . $job;
	}

	public static function isPortalActor(string $actor): bool
	{
		return str_starts_with($actor, 'portal:');
	}

	public static function portalId(string $actor): ?int
	{
		if (!str_starts_with($actor, 'portal:')) {
			return null;
		}
		$rest = substr($actor, 7);
		return preg_match('/^\d+$/', $rest) ? (int)$rest : null;
	}

	/** Human label for lists — never throws, falls back to a generic label. */
	public function displayName(string $actor): string
	{
		if (($pid = self::portalId($actor)) !== null) {
			$i = $this->identities->find($pid);
			return $i !== null ? (string)$i->getDisplayName() : 'Kontakt';
		}
		if (str_starts_with($actor, 'ulink:')) {
			$id = self::portalId('portal' . substr($actor, 5)) ?? 0;
			$l = $this->ulinks->find($id);
			return $l !== null ? (string)$l->getLabel() : 'Upload-Link';
		}
		if (str_starts_with($actor, 'system:')) {
			return 'System';
		}
		$u = $this->userManager->get($actor);
		return $u !== null ? $u->getDisplayName() : $actor;
	}
}

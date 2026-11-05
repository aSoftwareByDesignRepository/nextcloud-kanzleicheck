<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\Identity;
use OCA\PortalCheck\Db\IdentityMapper;
use OCA\PortalCheck\Db\Member;
use OCA\PortalCheck\Db\MemberMapper;
use OCA\PortalCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Client identities + memberships (US-P07). Identity = e-mail + display
 * name only (GDPR minimisation). E-mail is RFC-5321 ≤254, trimmed,
 * lower-cased — the natural unique key.
 */
class IdentityService
{
	public const MAX_MEMBERSHIPS = 200;

	public function __construct(
		private readonly IdentityMapper $identities,
		private readonly MemberMapper $members,
		) {
	}

	public static function normalizeEmail(string $email): string
	{
		return strtolower(trim($email));
	}

	public static function isValidEmail(string $email): bool
	{
		return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
	}

	/** Create or reuse by e-mail; display name only set on create. */
	public function upsert(string $email, string $displayName): Identity
	{
		$email = self::normalizeEmail($email);
		if (!self::isValidEmail($email)) {
			throw new ValidationException('VALIDATION_FAILED', ['email' => 'invalid']);
		}
		$existing = $this->identities->findByEmail($email);
		if ($existing !== null) {
			return $existing;
		}
		$i = new Identity();
		$i->setEmail($email);
		$i->setDisplayName(mb_substr(trim($displayName) !== '' ? $displayName : $email, 0, 190));
		$i->setStatus('active');
		$i->setCreatedAt(new \DateTimeImmutable());
		try {
			return $this->identities->insert($i);
		} catch (DbException $e) {
			// idem race: two concurrent creates on the same email — return the winner
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& ($winner = $this->identities->findByEmail($email)) !== null) {
				return $winner;
			}
			throw $e;
		}
	}

	public function addMembership(int $identityId, int $orgId, string $actor, string $role = 'member'): Member
	{
		if ($this->members->countByIdentity($identityId) >= self::MAX_MEMBERSHIPS) {
			throw new ValidationException('VALIDATION_FAILED', ['org' => 'membership_limit']);
		}
		$existing = $this->members->findByIdentityAndOrg($identityId, $orgId);
		if ($existing !== null) {
			return $existing;
		}
		$m = new Member();
		$m->setIdentityId($identityId);
		$m->setOrgId($orgId);
		$m->setRole(in_array($role, ['member', 'readonly'], true) ? $role : 'member');
		$m->setCreatedAt(new \DateTimeImmutable());
		$m->setCreatedBy($actor);
		try {
			return $this->members->insert($m);
		} catch (DbException $e) {
			// idem race: concurrent add on the same (identity, org) — return the winner
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& ($winner = $this->members->findByIdentityAndOrg($identityId, $orgId)) !== null) {
				return $winner;
			}
			throw $e;
		}
	}

	/** Active identity or null — disabled/erased identities cannot authenticate. */
	public function activeByEmail(string $email): ?Identity
	{
		$i = $this->identities->findByEmail(self::normalizeEmail($email));
		return $i !== null && $i->getStatus() === 'active' ? $i : null;
	}

	public function activeById(int $id): ?Identity
	{
		$i = $this->identities->find($id);
		return $i !== null && $i->getStatus() === 'active' ? $i : null;
	}

	/** @return list<int> org ids this identity may enter */
	public function orgIdsFor(int $identityId): array
	{
		return array_map(static fn($m) => (int)$m->getOrgId(), $this->members->findByIdentity($identityId));
	}

	public function isMember(int $identityId, int $orgId): bool
	{
		return $this->members->findByIdentityAndOrg($identityId, $orgId) !== null;
	}
}

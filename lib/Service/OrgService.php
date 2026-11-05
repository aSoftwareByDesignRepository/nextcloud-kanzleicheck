<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\Org;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;

/**
 * Client organisations (Mandat/Kunde/Objekt): CRUD, state machine
 * (active|offboarded), app-enablement against portalClients slots.
 * Enablement is licence-gated and fail-closed only on NEW enablement —
 * a shrunk key never revokes existing enabled orgs (US-P15 AC-3, NN-P10).
 *
 * unique-conflict:ok the ->update() sites in setAppEnabled()/offboard() only
 * SET app_on/state/updated_at — never the unique ref column. The ref-bearing
 * writes (create/update) carry REASON_UNIQUE catches mapped to 'ref taken'.
 */
class OrgService
{
	public const KINDS = ['company', 'private', 'object', 'other'];

	public function __construct(
		private readonly OrgMapper $orgs,
		private readonly AuditService $audit,
		private readonly LicenseService $license,
	) {
	}

	public function mustGet(int $id): Org
	{
		$o = $this->orgs->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		return $o;
	}

	public function findByRefPublic(string $ref): ?Org
	{
		return $this->orgs->findByRef($ref);
	}

	public function create(string $actor, string $name, ?string $ref, string $kind = 'company'): Org
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationException('VALIDATION_FAILED', ['name' => 'required']);
		}
		if (!in_array($kind, self::KINDS, true)) {
			$kind = 'company';
		}
		$ref = $ref !== null ? trim($ref) : null;
		$ref = $ref === '' ? null : ($ref !== null ? mb_substr($ref, 0, 64) : null);
		if ($ref !== null && $this->orgs->findByRef($ref) !== null) {
			throw new ValidationException('VALIDATION_FAILED', ['ref' => 'taken']);
		}
		$now = new \DateTimeImmutable();
		$o = new Org();
		$o->setName($name);
		$o->setRef($ref);
		$o->setKind($kind);
		$o->setState('active');
		$o->setAppOn(false);
		$o->setCreatedBy($actor);
		$o->setCreatedAt($now);
		$o->setUpdatedAt($now);
		try {
			$o = $this->orgs->insert($o);
		} catch (DbException $e) {
			// ref race: concurrent create with the same ref — loser gets the field error
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new ValidationException('VALIDATION_FAILED', ['ref' => 'taken']);
			}
			throw $e;
		}
		$this->audit->log($actor, 'org.create', 'org', (int)$o->getId());
		return $o;
	}

	public function update(string $actor, int $id, array $fields): Org
	{
		$o = $this->mustGet($id);
		if (isset($fields['name'])) {
			$name = trim((string)$fields['name']);
			if ($name === '' || mb_strlen($name) > 190) {
				throw new ValidationException('VALIDATION_FAILED', ['name' => 'required']);
			}
			$o->setName($name);
		}
		if (array_key_exists('ref', $fields)) {
			$ref = trim((string)($fields['ref'] ?? ''));
			$ref = $ref === '' ? null : mb_substr($ref, 0, 64);
			if ($ref !== null && ($other = $this->orgs->findByRef($ref)) !== null && (int)$other->getId() !== $id) {
				throw new ValidationException('VALIDATION_FAILED', ['ref' => 'taken']);
			}
			$o->setRef($ref);
		}
		if (isset($fields['kind']) && in_array($fields['kind'], self::KINDS, true)) {
			$o->setKind($fields['kind']);
		}
		if (array_key_exists('pcCustId', $fields)) {
			$v = $fields['pcCustId'];
			$o->setPcCustId($v === null || $v === '' ? null : (int)$v);
		}
		if (array_key_exists('bookUrl', $fields)) {
			$o->setBookUrl($this->validateBookingUrl((string)($fields['bookUrl'] ?? '')));
		}
		$o->setUpdatedAt(new \DateTimeImmutable());
		try {
			$this->orgs->update($o);
		} catch (DbException $e) {
			// ref race: two updates converging on the same ref — loser gets the field error
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new ValidationException('VALIDATION_FAILED', ['ref' => 'taken']);
			}
			throw $e;
		}
		return $o;
	}

	/**
	 * BookingCheck deep link must be a /pub/o/{slug} route of the same
	 * instance — nothing else is accepted (BOOKING_URL_INVALID, US-P30 AC-4).
	 */
	public function validateBookingUrl(string $url): ?string
	{
		$url = trim($url);
		if ($url === '') {
			return null;
		}
		if (!preg_match('~^/apps/bookingcheck/pub/o/[A-Za-z0-9_-]+/?$~', $url)
			&& !preg_match('~^/index\.php/apps/bookingcheck/pub/o/[A-Za-z0-9_-]+/?$~', $url)) {
			throw new ApiException(400, 'BOOKING_URL_INVALID', 'booking url must be a /pub/o/{slug} route of this instance');
		}
		return $url;
	}

	/**
	 * App-enablement slot race (§9.9): FOR UPDATE on the licence row inside a
	 * transaction → exactly one concurrent enable at the last slot wins.
	 */
	public function setAppEnabled(string $actor, int $orgId, bool $on): Org
	{
		$o = $this->mustGet($orgId);
		if ($on === (bool)$o->getAppOn()) {
			return $o;
		}
		if ($on) {
			if (!$this->license->tryAcquirePortalSlot()) {
				throw new ApiException(402, 'NO_PORTAL_SLOT', 'no portal client slot available');
			}
			$o->setAppOn(true);
			$o->setAppOnAt(new \DateTimeImmutable());
			$this->audit->log($actor, 'org.app_enable', 'org', $orgId);
		} else {
			$o->setAppOn(false);
			$this->audit->log($actor, 'org.app_disable', 'org', $orgId);
		}
		$o->setUpdatedAt(new \DateTimeImmutable());
		$this->orgs->update($o);
		return $o;
	}

	/** Offboard: memberships removed by caller, conversation archived read-only. */
	public function offboard(string $actor, int $orgId): Org
	{
		$o = $this->mustGet($orgId);
		$o->setState('offboarded');
		$o->setUpdatedAt(new \DateTimeImmutable());
		$this->orgs->update($o);
		$this->audit->log($actor, 'org.offboard', 'org', $orgId);
		return $o;
	}
}

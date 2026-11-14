<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\LicenseState;
use OCA\KanzleiCheck\Db\LicenseStateMapper;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\License\Kzc2Codec;
use OCP\IDBConnection;

/**
 * KZC2 licensing (SPEC §9.7, LICENSE-KZC2-SPEC).
 *
 * - Web routes are NEVER gated (NN-P06).
 * - Companion staff role: seat-gated (LICENSE_REQUIRED/EXPIRED/NO_MOBILE_SEAT).
 * - Companion client role: per-org app enablement; 30-day grace after expiry.
 * - Slot acquisition is a SELECT…FOR UPDATE + counter write inside a
 *   transaction — concurrent enable at the last slot → exactly one wins.
 * - A shrunk key never revokes enabled orgs (NN-P10): enablement is only
 *   blocked going forward.
 */
class LicenseService
{
	public const GRACE_DAYS = 30;

	public function __construct(
		private readonly LicenseStateMapper $states,
		private readonly OrgMapper $orgs,
		private readonly IDBConnection $db,
	) {
	}

	public function apply(string $wireKey, string $actor): LicenseState
	{
		$parsed = Kzc2Codec::parseAndVerify($wireKey);
		if ($parsed === null) {
			throw new ApiException(400, 'VALIDATION_FAILED', Kzc2Codec::classifyError($wireKey));
		}
		$p = $parsed['payload'];
		$s = new LicenseState();
		$s->setKeyFingerprint(hash('sha256', $parsed['payloadB64'] . '.' . $parsed['signatureB64']));
		$s->setPayloadJson(Kzc2Codec::canonicalJson($p));
		$s->setSignatureB64($parsed['signatureB64']);
		$s->setCustomerId($p['customerId']);
		$s->setValidUntil($p['validUntil']);
		$s->setMobileSeats((int)$p['mobileSeats']);
		$s->setPortalClients((int)$p['portalClients']);
		$s->setAppliedBy($actor);
		$s->setAppliedAt(new \DateTimeImmutable());
		return $this->states->save($s);
	}

	public function state(): ?LicenseState
	{
		return $this->states->load();
	}

	/** none|valid|grace|expired */
	public function status(): string
	{
		$s = $this->state();
		if ($s === null) {
			return 'none';
		}
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		if (Kzc2Codec::isValidOn($s->getValidUntil(), $today)) {
			return 'valid';
		}
		$graceEnd = (new \DateTimeImmutable($s->getValidUntil()))->modify('+' . self::GRACE_DAYS . ' days')->format('Y-m-d');
		return $today <= $graceEnd ? 'grace' : 'expired';
	}

	/**
	 * Atomic portal-client slot acquisition: lock the licence row, count
	 * enabled orgs, enable iff used < portalClients. Returns false on a
	 * full quota — callers surface NO_PORTAL_SLOT.
	 */
	public function tryAcquirePortalSlot(): bool
	{
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('portal_clients')->from('kzc_license_state')
				->where($qb->expr()->eq('id', $qb->createNamedParameter(LicenseStateMapper::SINGLETON_ID)))
				->forUpdate();
			$cap = $qb->executeQuery()->fetchOne();
			if ($cap === false || (int)$cap <= $this->orgs->countAppEnabled()) {
				$this->db->commit();
				return false;
			}
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function portalClientsTotal(): int
	{
		$s = $this->state();
		return $s !== null ? (int)$s->getPortalClients() : 0;
	}

	/** Companion staff seat gate (client orgs use app-enablement instead). */
	public function staffGate(string $uid): string
	{
		$st = $this->status();
		if ($st === 'none') {
			return 'LICENSE_REQUIRED';
		}
		if ($st === 'expired') {
			return 'LICENSE_EXPIRED';
		}
		return 'ok';
	}
}

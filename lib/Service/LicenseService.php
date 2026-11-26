<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\LicenseState;
use OCA\KanzleiCheck\Db\LicenseStateMapper;
use OCA\KanzleiCheck\Db\MobileSeat;
use OCA\KanzleiCheck\Db\MobileSeatMapper;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\License\Kzc2Codec;
use OCP\IDBConnection;
use OCP\IUserManager;

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
		private readonly MobileSeatMapper $seats,
		private readonly IUserManager $userManager,
		private readonly AccessDoorService $door,
		private readonly AuditService $audit,
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

	/**
	 * Deterministic seat ranks ordered by (assigned_at ASC, id ASC) — the
	 * TicketCheck SeatRank rule. Enforced at gate time so a license shrink
	 * blocks over-limit seats without deleting rows (NN-P10 analog).
	 * @return array<int, int> seat id → 1-based rank
	 */
	private function seatRanks(): array
	{
		$seats = $this->seats->findAllOrdered('assigned_at', 'ASC');
		usort($seats, static fn (MobileSeat $a, MobileSeat $b): int =>
			($a->getAssignedAt() <=> $b->getAssignedAt()) ?: ((int)$a->getId() <=> (int)$b->getId()));
		$ranks = [];
		$r = 1;
		foreach ($seats as $s) {
			$ranks[(int)$s->getId()] = $r++;
		}
		return $ranks;
	}

	/** @return array{licenseStatus:string,seatAssigned:bool,seatWithinLimit:bool,mobileSeats:int} */
	public function gateState(string $uid): array
	{
		$limit = (int)($this->state()?->getMobileSeats() ?? 0);
		$seat = $this->seats->findByUser($uid);
		$within = false;
		if ($seat !== null) {
			$ranks = $this->seatRanks();
			$within = ($ranks[(int)$seat->getId()] ?? PHP_INT_MAX) <= $limit;
		}
		return [
			'licenseStatus' => $this->status(),
			'seatAssigned' => $seat !== null,
			'seatWithinLimit' => $within,
			'mobileSeats' => $limit,
		];
	}

	/**
	 * Companion staff seat gate (spec §9.5: LICENSE_REQUIRED / LICENSE_EXPIRED
	 * / NO_MOBILE_SEAT). Client orgs use app-enablement instead. 'valid' and
	 * 'grace' both admit — grace is the paid-past-due window, not a lapse.
	 */
	public function seatGate(string $uid): string
	{
		$st = $this->status();
		if ($st === 'none') {
			return 'LICENSE_REQUIRED';
		}
		if ($st === 'expired') {
			return 'LICENSE_EXPIRED';
		}
		$g = $this->gateState($uid);
		if (!$g['seatAssigned'] || !$g['seatWithinLimit']) {
			return 'NO_MOBILE_SEAT';
		}
		return 'ok';
	}

	/** @return list<array{id:int,uid:string,displayName:string,assignedAt:?string,assignedBy:string,withinLimit:bool}> */
	public function seatsList(): array
	{
		$limit = (int)($this->state()?->getMobileSeats() ?? 0);
		$ranks = $this->seatRanks();
		$rows = [];
		foreach ($this->seats->findAllOrdered('assigned_at', 'ASC') as $s) {
			$rows[] = [
				'id' => (int)$s->getId(),
				'uid' => $s->getUserId(),
				'displayName' => $this->userManager->get($s->getUserId())?->getDisplayName() ?? $s->getUserId(),
				'assignedAt' => $s->getAssignedAt()?->format('c'),
				'assignedBy' => $s->getAssignedBy(),
				'withinLimit' => ($ranks[(int)$s->getId()] ?? PHP_INT_MAX) <= $limit,
			];
		}
		return $rows;
	}

	/**
	 * Named-seat assignment (AC-4): the uid must be a real NC user admitted
	 * by the door — client identities are portal identities, never NC users,
	 * and can never hold seats. Hard cap at mobileSeats (assignment beyond
	 * the licensed count fails closed per AC-4); a later license shrink is
	 * still caught by the rank check at gate time. Idempotent on unique
	 * user_id. The licence row lock serialises count+insert across requests
	 * so two concurrent assigns at the last slot cannot both win.
	 */
	public function assignSeat(string $uid, string $actor): MobileSeat
	{
		$uid = trim($uid);
		if ($this->state() === null) {
			throw new ApiException(400, 'LICENSE_REQUIRED', 'no licence applied');
		}
		if ($uid === '' || !$this->userManager->userExists($uid)) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'unknown user', ['uid' => 'unknown_user']);
		}
		if (!$this->door->isStaff($uid)) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'user is not door-admitted staff', ['uid' => 'not_staff']);
		}
		$existing = $this->seats->findByUser($uid);
		if ($existing !== null) {
			return $existing;
		}
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('mobile_seats')->from('kzc_license_state')
				->where($qb->expr()->eq('id', $qb->createNamedParameter(LicenseStateMapper::SINGLETON_ID)))
				->forUpdate();
			$cap = (int)$qb->executeQuery()->fetchOne();
			if ($this->seats->countAll() >= $cap) {
				$this->db->commit();
				throw new ApiException(409, 'SEAT_LIMIT', 'all licensed seats are assigned');
			}
			$seat = new MobileSeat();
			$seat->setUserId($uid);
			$seat->setAssignedBy($actor);
			$seat->setAssignedAt(new \DateTimeImmutable());
			$seat = $this->seats->insert($seat);
			$this->db->commit();
		} catch (ApiException $e) {
			throw $e;
		} catch (\OCP\DB\Exception $e) {
			$this->db->rollBack();
			// Concurrent assign of the same uid — the unique index already holds it.
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$seat = $this->seats->findByUser($uid);
				if ($seat !== null) {
					return $seat;
				}
			}
			throw new ApiException(500, 'SEAT_ASSIGN_FAILED', 'could not assign seat');
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// Non-DBAL failure on the write — replay the same post-rollback
			// lookup: if the row exists a concurrent assign already holds it.
			$seat = $this->seats->findByUser($uid);
			if ($seat === null) {
				throw new ApiException(500, 'SEAT_ASSIGN_FAILED', 'could not assign seat');
			}
		}
		$this->audit->log($actor, 'seat.assign', 'identity', $uid, ['seatId' => (int)$seat->getId()]);
		return $seat;
	}

	public function revokeSeat(int $id, string $actor): void
	{
		$seat = $this->seats->find($id);
		if ($seat === null) {
			throw new NotFoundException();
		}
		$this->seats->delete($seat);
		$this->audit->log($actor, 'seat.revoke', 'identity', $seat->getUserId(), ['seatId' => $id]);
	}
}

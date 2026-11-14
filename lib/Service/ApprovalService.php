<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\Approval;
use OCA\KanzleiCheck\Db\ApprovalMapper;
use OCA\KanzleiCheck\Db\Otp;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Code-confirmed approvals (US-P23). An approval binds to file id AND
 * SHA-256 at request time; the client's decision requires a purpose-bound
 * OTP (approve:<id>). Void rules: file deleted, or etag changed AND hash
 * differs. Reads compare etag only (cheap); hash recompute happens inside
 * the decide transaction and in the scheduler void sweep (ZF-02).
 *
 * Product copy calls this "Freigabe"/"approval" — never "Signatur" (NN-P16).
 */
class ApprovalService
{
	public function __construct(
		private readonly ApprovalMapper $approvals,
		private readonly OtpService $otp,
		private readonly AuditService $audit,
		private readonly DocumentService $docs,
		private readonly IDBConnection $db,
	) {
	}

	public function mustGetInOrg(int $id, int $orgId): Approval
	{
		$a = $this->approvals->find($id);
		if ($a === null || (int)$a->getOrgId() !== $orgId) {
			throw new NotFoundException();
		}
		return $a;
	}

	/** Create pending approval bound to the file's CURRENT sha256 + etag. */
	public function request(string $actor, int $orgId, int $fileId, ?string $deadline, ?string $note, ?int $requiredIdentityId): Approval
	{
		$node = $this->docs->documentNodeForOrg($orgId, $fileId); // confined
		$sha = $this->docs->hashFile($node);
		$etag = (string)$node->getEtag();
		$a = new Approval();
		$a->setOrgId($orgId);
		$a->setFileId($fileId);
		$a->setFileSha256($sha);
		$a->setFileEtag($etag);
		$a->setFileName(mb_substr($node->getName(), 0, 255));
		$a->setRequiredIdentityId($requiredIdentityId);
		$a->setDeadline($deadline !== null && $deadline !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline) ?: null : null);
		$a->setNote($note !== null ? mb_substr($note, 0, 1000) : null);
		$a->setState('pending');
		$a->setRequestedBy($actor);
		$a->setRequestedAt(new \DateTimeImmutable());
		$a = $this->approvals->insert($a);
		$this->audit->log($actor, 'approval.request', 'approval', (int)$a->getId());
		return $a;
	}

	/**
	 * Etag-based void check (ZF-02): returns 'ok'|'void' — rehash only when
	 * the etag moved. Called on reads and in the decide transaction.
	 */
	public function checkVoid(Approval $a): string
	{
		if ($a->getState() !== 'pending') {
			return 'ok';
		}
		$node = $this->docs->findNodeById($a->getFileId());
		if ($node === null) {
			$this->void($a, 'deleted');
			return 'void';
		}
		if (!$node instanceof \OCP\Files\File) {
			$this->void($a, 'deleted');
			return 'void';
		}
		if ((string)$node->getEtag() !== $a->getFileEtag()) {
			$sha = $this->docs->hashFile($node);
			if (!hash_equals($a->getFileSha256(), $sha)) {
				$this->void($a, 'changed');
				return 'void';
			}
			// etag-only change (move/rename) — rebind etag, hash unchanged
			$a->setFileEtag((string)$node->getEtag());
			$this->approvals->update($a);
		}
		return 'ok';
	}

	private function void(Approval $a, string $reason): void
	{
		$a->setState('void');
		$a->setReason($reason);
		$this->approvals->update($a);
		$this->audit->log(ActorResolver::system('scheduler'), 'approval.void', 'approval', (int)$a->getId(), ['reason' => $reason]);
	}

	/**
	 * Issue the approval code — purpose approve:<id> (AF-08). Returns the
	 * otp id so decide() can bind it.
	 * @return array{code:string,otpId:int}
	 */
	public function start(int $approvalId, string $email, string $ip): array
	{
		[$code, , $otpId] = $this->otp->issue($email, OtpService::approvalPurpose($approvalId), $ip, false);
		return ['code' => $code, 'otpId' => $otpId];
	}

	/**
	 * Decide. For 'approve' the purpose-bound code is verified INSIDE the
	 * transaction after re-checking the hash — a file swap between the
	 * client's page load and the code entry can never produce a stale
	 * approval (§9.9 race row).
	 */
	public function decide(string $actor, string $email, int $approvalId, int $orgId, string $decision, ?string $code, ?string $reason): Approval
	{
		if (!in_array($decision, ['approve', 'reject'], true)) {
			throw new ValidationException('VALIDATION_FAILED', ['decision' => 'invalid']);
		}
		$this->db->beginTransaction();
		try {
			$a = $this->mustGetInOrg($approvalId, $orgId);
			if ($a->getState() !== 'pending') {
				throw new ApiException(409, 'APPROVAL_VOID', 'approval not pending');
			}
			// required approver: only that identity may decide
			if ($a->getRequiredIdentityId() !== null) {
				$expected = ActorResolver::portal((int)$a->getRequiredIdentityId());
				if ($actor !== $expected) {
					throw new NotFoundException();
				}
			}
			if ($this->checkVoid($a) === 'void') {
				throw new ApiException(410, 'APPROVAL_VOID', 'document changed');
			}
			if ($decision === 'reject') {
				$reason = trim((string)$reason);
				if ($reason === '' || mb_strlen($reason) > 1000) {
					throw new ValidationException('VALIDATION_FAILED', ['reason' => 'required']);
				}
				$a->setState('rejected');
				$a->setReason($reason);
			} else {
				if ($code === null || $code === '') {
					throw new ValidationException('VALIDATION_FAILED', ['code' => 'required']);
				}
				$otpId = $this->otp->verify($email, OtpService::approvalPurpose($approvalId), $code);
				if ($otpId === null) {
					throw new ApiException(400, 'INVALID_CODE', 'invalid or expired code');
				}
				// final integrity check inside the transaction
				$node = $this->docs->findNodeById($a->getFileId());
				if (!$node instanceof \OCP\Files\File || !hash_equals($a->getFileSha256(), $this->docs->hashFile($node))) {
					$this->void($a, 'changed');
					throw new ApiException(410, 'APPROVAL_VOID', 'document changed');
				}
				$a->setState('approved');
				$a->setConfirmMethod('email_code');
				$a->setOtpId($otpId);
			}
			$a->setDecidedBy($actor);
			$a->setDecidedAt(new \DateTimeImmutable());
			$this->approvals->update($a);
			$this->db->commit();
			$this->audit->log($actor, 'approval.' . $decision, 'approval', $approvalId);
			return $a;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function withdraw(string $actor, int $approvalId): Approval
	{
		$a = $this->approvals->find($approvalId);
		if ($a === null) {
			throw new NotFoundException();
		}
		if ($a->getState() !== 'pending') {
			throw new ApiException(409, 'APPROVAL_VOID', 'not pending');
		}
		$a->setState('withdrawn');
		$this->approvals->update($a);
		$this->audit->log($actor, 'approval.withdraw', 'approval', $approvalId);
		return $a;
	}
}

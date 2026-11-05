<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\UploadLink;
use OCA\PortalCheck\Db\UploadLinkMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/** Third-party upload links (US-P29): upload-only, hashed 256-bit token, expiry, counters CAS. */
class UploadLinkService
{
	public function __construct(
		private readonly UploadLinkMapper $links,
		private readonly TokenService $tokens,
		private readonly IDBConnection $db,
	) {
	}

	/** @return array{link:UploadLink,token:string} — token shown ONCE. */
	public function create(string $actor, int $orgId, string $label, int $expiryDays, int $maxFiles, int $maxBytes, bool $showOrg): array
	{
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > 190) {
			throw new \OCA\PortalCheck\Exception\ValidationException('VALIDATION_FAILED', ['label' => 'required']);
		}
		$expiryDays = max(1, min(30, $expiryDays));
		$maxFiles = max(1, min(50, $maxFiles));
		$maxBytes = max(1024 * 1024, min(1024 * 1024 * 1024, $maxBytes));
		[$token, $hash] = $this->tokens->mint();
		$l = new UploadLink();
		$l->setOrgId($orgId);
		$l->setLabel($label);
		$l->setTokenHash($hash);
		$l->setExpiresAt((new \DateTimeImmutable())->modify('+' . $expiryDays . ' days'));
		$l->setMaxFiles($maxFiles);
		$l->setUsedFiles(0);
		$l->setMaxBytes($maxBytes);
		$l->setUsedBytes(0);
		$l->setShowOrg($showOrg);
		$l->setCreatedBy($actor);
		$l->setCreatedAt(new \DateTimeImmutable());
		for ($attempt = 0; ; $attempt++) {
			try {
				$l = $this->links->insert($l);
				break;
			} catch (DbException $e) {
				// token_hash collision — remint and retry
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
				[$token, $hash] = $this->tokens->mint();
				$l->setTokenHash($hash);
			}
		}
		return ['link' => $l, 'token' => $token];
	}

	/** Resolve token → live link or uniform miss (expired/revoked/exhausted). */
	public function resolve(string $token): ?UploadLink
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$l = $this->links->findByTokenHash($this->tokens->hash($token));
		if ($l === null || $l->getRevokedAt() !== null) {
			return null;
		}
		if ($l->getExpiresAt() < new \DateTimeImmutable()) {
			return null;
		}
		if ((int)$l->getUsedFiles() >= (int)$l->getMaxFiles()
			|| (int)$l->getUsedBytes() >= (int)$l->getMaxBytes()) {
			return null;
		}
		return $l;
	}

	/**
	 * Atomic counter claim before storing a file (§9.9): 0 rows →
	 * LINK_EXHAUSTED — a concurrent last-slot upload can never overshoot.
	 */
	public function claimSlot(int $linkId, int $bytes): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_ulink')
			->set('used_files', $qb->createFunction('used_files + 1'))
			->set('used_bytes', $qb->createFunction('used_bytes + ' . max(0, $bytes)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($linkId)))
			->andWhere($qb->expr()->lt('used_files', 'max_files'))
			->andWhere($qb->expr()->lte($qb->createFunction('used_bytes + ' . max(0, $bytes)), 'max_bytes'));
		if ($qb->executeStatement() !== 1) {
			throw new ApiException(410, 'LINK_EXHAUSTED', 'link exhausted');
		}
	}

	public function revoke(int $linkId, int $orgId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_ulink')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($linkId)))
			->andWhere($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}
}

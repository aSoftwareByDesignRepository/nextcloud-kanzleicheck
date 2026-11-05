<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\IdentityMapper;
use OCA\PortalCheck\Db\MemberMapper;
use OCA\PortalCheck\Db\MessageMapper;
use OCA\PortalCheck\Db\NotifyMapper;
use OCA\PortalCheck\Db\DeviceMapper;
use OCA\PortalCheck\Db\PortalSessionMapper;
use OCA\PortalCheck\Db\OtpMapper;
use OCP\IDBConnection;

/** GDPR export/erase/offboard (US-P19). Never touches NC user accounts (NN-P02/P14). */
class GdprService
{
	public function __construct(
		private readonly IdentityMapper $identities,
		private readonly MemberMapper $members,
		private readonly MessageMapper $messages,
		private readonly NotifyMapper $notify,
		private readonly DeviceMapper $devices,
		private readonly PortalSessionMapper $sessions,
		private readonly OtpMapper $otps,
		private readonly IDBConnection $db,
		private readonly AuditService $audit,
		private readonly ?SyncBindingService $syncBindings = null,
		private readonly ?\OCA\PortalCheck\Db\SyncBindingMapper $syncMapper = null,
	) {
	}

	/** Export identity → JSON document (ZIP assembly is the caller's IO). */
	public function exportIdentity(int $identityId): array
	{
		$i = $this->identities->find($identityId);
		if ($i === null) {
			throw new \OCA\PortalCheck\Exception\NotFoundException();
		}
		$members = array_map(static fn($m) => ['orgId' => (int)$m->getOrgId(), 'role' => $m->getRole(), 'createdAt' => $m->getCreatedAt()->format('c')], $this->members->findByIdentity($identityId));
		$devices = array_map(static fn($d) => ['name' => $d->getDeviceName(), 'platform' => $d->getPlatform(), 'pairedAt' => $d->getCreatedAt()->format('c'), 'lastUsedAt' => $d->getLastUsedAt()->format('c')], $this->devices->findByIdentity($identityId));
		$msgs = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'conv_id', 'body', 'created_at')->from('ptl_message')
			->where($qb->expr()->eq('author', $qb->createNamedParameter(ActorResolver::portal($identityId))));
		foreach ($qb->executeQuery()->fetchAll() ?: [] as $r) {
			$msgs[] = ['id' => (int)$r['id'], 'convId' => (int)$r['conv_id'], 'body' => $r['body'], 'createdAt' => $r['created_at']];
		}
		$bindings = [];
		if ($this->syncMapper !== null) {
			foreach ($this->syncMapper->findByIdentity($identityId) as $b) {
				$bindings[] = ['bindingId' => (int)$b->getId(), 'orgId' => (int)$b->getOrgId(), 'perms' => $b->getPerms(), 'state' => $b->getState(), 'createdAt' => $b->getCreatedAt()->format('c')];
			}
		}
		return [
			'identity' => ['id' => (int)$i->getId(), 'email' => $i->getEmail(), 'displayName' => $i->getDisplayName(), 'status' => $i->getStatus(), 'ncUid' => $i->getNcUid(), 'ncUidMismatch' => (bool)$i->getNcUidMismatch(), 'createdAt' => $i->getCreatedAt()->format('c')],
			'memberships' => $members,
			'devices' => $devices,
			'messages' => $msgs,
			'syncBindings' => $bindings,
		];
	}

	/** Erase: anonymise identity, drop credentials; optionally purge messages. */
	public function erase(string $actor, int $identityId, bool $deleteMessages): void
	{
		// US-S07 cascade first: shares revoked + binding rows dropped +
		// nc_uid cleared — BEFORE the anonymising transaction. The attached
		// NC user is never touched (D-B08/NN-01).
		$this->syncBindings?->cascadeErase($actor, $identityId);
		$this->db->beginTransaction();
		try {
			$i = $this->identities->find($identityId);
			if ($i === null) {
				throw new \OCA\PortalCheck\Exception\NotFoundException();
			}
			// unique-conflict:ok the erase rewrite sets email to a per-PK literal
			// ('erased-<id>@invalid') — two identities can never produce the same
			// value, so ptl_ident_email_uq is unreachable on this write.
			$qb = $this->db->getQueryBuilder();
			$qb->update('ptl_identity')
				->set('email', $qb->createNamedParameter('erased-' . $identityId . '@invalid'))
				->set('display_name', $qb->createNamedParameter('Gelöschter Kontakt'))
				->set('status', $qb->createNamedParameter('erased'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($identityId)));
			$qb->executeStatement();
			$this->sessions->revokeForIdentity($identityId);
			$this->devices->revokeForIdentity($identityId);
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ptl_otp')->where($qb->expr()->eq('email', $qb->createNamedParameter($i->getEmail())));
			$qb->executeStatement();
			$this->notify->deleteForIdentity($identityId);
			if ($deleteMessages) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('ptl_message')->where($qb->expr()->eq('author', $qb->createNamedParameter(ActorResolver::portal($identityId))));
				$qb->executeStatement();
			}
			$this->db->commit();
			$this->audit->log($actor, 'identity.erase', 'identity', $identityId, ['messages' => $deleteMessages]);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

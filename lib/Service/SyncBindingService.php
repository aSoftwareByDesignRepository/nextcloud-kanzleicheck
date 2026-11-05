<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\Identity;
use OCA\PortalCheck\Db\IdentityMapper;
use OCA\PortalCheck\Db\MemberMapper;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Db\SyncBinding;
use OCA\PortalCheck\Db\SyncBindingMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Exception\ValidationException;
use OCP\Constants;
use OCP\DB\Exception as DbException;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * NC-user binding (nc-user-binding spec v1.3.0): the single owner of
 * attach / enable / verify / revoke / repair / reconcile — every caller
 * (org detail, offboard, GDPR, import) delegates here (§4 anti-fan-out).
 *
 * Hard rules:
 *  - Platform state is the only read authority (D-B06): verify() resolves
 *    the live share via IShareManager on every detail read (rate-capped);
 *    ptl_sync.state is a list-view cache only.
 *  - Reshare bit is never set on any binding share (D-B07) — rw = read +
 *    create + update + delete, ro = read. Verified and normalised.
 *  - enable() is share-first, compensate-on-conflict (D-B13/ADR-Z01): the
 *    share is created BEFORE the row insert; a REASON_UNIQUE conflict
 *    deletes the just-created share — a row without a share self-heals
 *    via broken_share, a share without a row is invisible forever.
 *  - Verify/reconcile/job paths are strictly read-only w.r.t. shares
 *    (D-B18, NN-11): they compute state and update the ptl_sync cache row,
 *    they never call orgFolder() (read path must not write — ZF-09) and
 *    never mutate an IShare.
 *  - Bound client accounts never become staff: attach and enable re-check
 *    the door (D-B05) and refuse while the door is Open (SYNC_DOOR_OPEN).
 *  - The portal never deletes or disables an NC user (D-B08, NN-01).
 */
class SyncBindingService
{
	public const VERIFY_THROTTLE_SECONDS = 60;

	public function __construct(
		private readonly SyncBindingMapper $syncs,
		private readonly IdentityMapper $identities,
		private readonly MemberMapper $members,
		private readonly OrgMapper $orgs,
		private readonly DocumentService $docs,
		private readonly AccessDoorService $door,
		private readonly IUserManager $userManager,
		private readonly IShareManager $shareManager,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
		private readonly ?NotifyService $notify = null,
	) {
	}

	// ── helpers ─────────────────────────────────────────────────────

	private function user(string $uid): ?IUser
	{
		return $this->userManager->get($uid);
	}

	// unique-conflict:ok the remaining ->update() sites in this file only SET
	// non-unique columns (nc_uid/origin/mismatch on ptl_identity, folder_id on
	// ptl_org, state/share_id/verified_at on ptl_sync, revoked_at on ptl_member
	// cascades) — the unique keys identity_id+org_id / email / ref are never
	// rewritten by an update path. The sole insert path (enable) carries the
	// REASON_UNIQUE catch below with share compensation (ADR-Z01).

	/** @return int<0, 31> */
	public static function permsMask(string $perms): int
	{
		return $perms === SyncBinding::PERMS_RO
			? Constants::PERMISSION_READ
			: Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE;
	}

	private function mustIdentity(int $identityId): Identity
	{
		$i = $this->identities->find($identityId);
		if ($i === null || $i->getStatus() !== 'active') {
			throw new NotFoundException();
		}
		return $i;
	}

	private function mustBinding(int $bindingId): SyncBinding
	{
		$b = $this->syncs->find($bindingId);
		if ($b === null) {
			throw new NotFoundException();
		}
		return $b;
	}

	/**
	 * Live share for a binding — platform read is the only authority.
	 * Prefers the stored share id, falls back to any share of the same org
	 * folder to the uid (covers externally re-appeared shares). Never a
	 * write call.
	 */
	private function liveShare(SyncBinding $b, string $uid, ?Folder $node): ?IShare
	{
		$shareId = $b->getShareId();
		if ($shareId !== null && $shareId !== '') {
			try {
				return $this->shareManager->getShareById($shareId, $uid);
			} catch (\Throwable) {
				// fall through to the folder-scoped lookup
			}
		}
		if ($node !== null) {
			try {
				foreach ($this->shareManager->getSharedWith($uid, IShare::TYPE_USER, $node, 10, 0) as $s) {
					if ((int)$s->getNodeId() === (int)$node->getId()) {
						return $s;
					}
				}
			} catch (\Throwable) {
				// mount-less lookup failures degrade to "no share" — never fatal on a read path
			}
		}
		return null;
	}

	private function orgFolderNode(int $orgId): ?Folder
	{
		$org = $this->orgs->find($orgId);
		$fid = $org?->getFolderId();
		if ($fid === null || (int)$fid <= 0) {
			return null;
		}
		$node = $this->docs->findNodeById((int)$fid);
		return $node instanceof Folder ? $node : null;
	}

	// ── attach / unbind (identity layer, D-B01) ─────────────────────

	/**
	 * Attach a pre-existing NC account to an identity (US-S01).
	 * Deny-by-default on the link decision: email-match OR explicit
	 * accept_mismatch / accept_shared (D-B15, NN-12).
	 */
	public function attach(string $actor, int $identityId, string $ncUid, bool $acceptMismatch = false, bool $acceptShared = false): Identity
	{
		$identity = $this->mustIdentity($identityId);
		$ncUid = trim($ncUid);
		if ($ncUid === '' || strlen($ncUid) > 64 || !$this->userManager->userExists($ncUid)) {
			throw new ApiException(400, 'SYNC_UID_UNKNOWN', 'unknown account');
		}
		// Door BEFORE the staff leg: under an Open door every uid resolves as
		// staff, which would surface the wrong, non-actionable error (D-B05).
		if (!$this->door->isRestricted()) {
			throw new ApiException(409, 'SYNC_DOOR_OPEN', 'sync bindings require a restricted access door');
		}
		if ($this->door->isStaff($ncUid)) {
			throw new ApiException(409, 'SYNC_UID_IS_STAFF', 'this account is admitted staff');
		}
		$user = $this->user($ncUid);
		if ($user === null || !$user->isEnabled()) {
			throw new ApiException(409, 'SYNC_UID_DISABLED', 'account is disabled');
		}
		if ($identity->getNcUid() !== null && $identity->getNcUid() !== '') {
			throw new ApiException(409, 'SYNC_ALREADY_BOUND', 'identity is already bound — unbind first');
		}
		$other = $this->identities->findByNcUid($ncUid);
		$shared = $other !== null && (int)$other->getId() !== $identityId;
		if ($shared && !$acceptShared) {
			throw new ApiException(409, 'SYNC_UID_SHARED', 'account is already bound to a different identity');
		}
		$ncMail = IdentityService::normalizeEmail((string)($user->getEMailAddress() ?? ''));
		$mismatch = $ncMail === '' || $ncMail !== IdentityService::normalizeEmail($identity->getEmail());
		if ($mismatch && !$acceptMismatch) {
			throw new ApiException(409, 'SYNC_EMAIL_MISMATCH', 'account e-mail does not match the identity e-mail');
		}
		$identity->setNcUid($ncUid);
		$identity->setNcUidOrigin('attach');
		$identity->setNcUidMismatch($mismatch);
		$this->identities->update($identity);
		$this->audit->log($actor, 'sync.bind', 'identity', $identityId, ['uid' => $ncUid, 'mismatch' => $mismatch, 'shared_uid' => $shared]);
		return $identity;
	}

	/**
	 * Unbind: clears nc_uid; refuses while live bindings exist — stale
	 * shares to the old uid must be revoked first (§9.4 re-bind rule).
	 */
	public function unbind(string $actor, int $identityId): void
	{
		$identity = $this->mustIdentity($identityId);
		if ($identity->getNcUid() === null || $identity->getNcUid() === '') {
			return; // idempotent
		}
		$live = 0;
		foreach ($this->syncs->findByIdentity($identityId) as $b) {
			if (!$b->isTerminal()) {
				$live++;
			}
		}
		if ($live > 0) {
			throw new ApiException(409, 'SYNC_BINDINGS_LIVE', $live . ' live binding(s) — revoke them first');
		}
		$identity->setNcUid(null);
		$identity->setNcUidOrigin(null);
		$identity->setNcUidMismatch(false);
		$this->identities->update($identity);
		$this->audit->log($actor, 'sync.unbind', 'identity', $identityId);
	}

	// ── enable (share binding, share-first ADR-Z01) ─────────────────

	public function enable(string $actor, int $orgId, int $identityId, string $perms = SyncBinding::PERMS_RW): SyncBinding
	{
		$perms = $perms === SyncBinding::PERMS_RO ? SyncBinding::PERMS_RO : SyncBinding::PERMS_RW;
		$org = $this->orgs->find($orgId);
		if ($org === null) {
			throw new NotFoundException();
		}
		$identity = $this->mustIdentity($identityId);
		if ($this->members->findByIdentityAndOrg($identityId, $orgId) === null) {
			throw new NotFoundException();
		}
		// org-state assert at write time (ZF-03) — the enable-vs-offboard race
		// is closed by this check plus the cascade's second sweep.
		if ($org->getState() !== 'active') {
			throw new ApiException(409, 'SYNC_ORG_OFFBOARDED', 'org is offboarded');
		}
		$ncUid = $identity->getNcUid();
		if ($ncUid === null || $ncUid === '') {
			throw new ApiException(400, 'SYNC_NOT_BOUND', 'identity has no bound account');
		}
		if (!$this->door->isRestricted()) {
			throw new ApiException(409, 'SYNC_DOOR_OPEN', 'sync bindings require a restricted access door');
		}
		if ($this->door->isStaff($ncUid)) {
			throw new ApiException(409, 'SYNC_UID_IS_STAFF', 'bound account is admitted staff');
		}
		$user = $this->user($ncUid);
		if ($user === null) {
			throw new ApiException(400, 'SYNC_UID_UNKNOWN', 'bound account no longer exists');
		}
		if (!$user->isEnabled()) {
			throw new ApiException(409, 'SYNC_UID_DISABLED', 'bound account is disabled');
		}
		$existing = $this->syncs->findByIdentityAndOrg($identityId, $orgId);
		if ($existing !== null && !$existing->isTerminal()) {
			return $existing; // idempotent (US-S02 AC-3)
		}
		if (!$this->docs->rootHealthy()) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'documents root is not healthy');
		}
		$folder = $this->docs->orgFolder($orgId, (string)$org->getName(), $org->getFolderId(), $org->getFolderPath());
		if (!$folder instanceof Folder) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'org folder unavailable');
		}
		// rebind the org to the live folder id (file-id binding, §9.6)
		if ((int)$org->getFolderId() !== (int)$folder->getId()) {
			$org->setFolderId((int)$folder->getId());
			$this->orgs->update($org);
		}
		$share = $this->createOrgShare($actor, $folder, $ncUid, $perms);
		$state = $share->getStatus() === IShare::STATUS_PENDING ? SyncBinding::ST_PENDING : SyncBinding::ST_ACTIVE;
		$now = new \DateTimeImmutable();
		if ($existing !== null) {
			// revoked row still occupies the unique key — revive it in place
			$b = $existing;
		} else {
			$b = new SyncBinding();
			$b->setIdentityId($identityId);
			$b->setOrgId($orgId);
			$b->setCreatedBy($actor);
			$b->setCreatedAt($now);
		}
		$b->setShareId($share->getId());
		$b->setShareNodeId((int)$folder->getId());
		$b->setSharedBy($actor);
		$b->setPerms($perms);
		$b->setState($state);
		// creation is not verification — leave last_verify_at NULL so the
		// next read live-verifies (an externally-broken fresh share must
		// surface on the first org-detail render, not 60 s later).
		$b->setLastVerifyAt(null);
		$b->setUpdatedAt($now);
		try {
			$b = $existing !== null ? $this->syncs->update($b) : $this->syncs->insert($b);
		} catch (\Throwable $e) {
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// ADR-Z01 compensation: delete the just-created share so no
				// orphan share escapes the binding registry.
				try {
					$this->shareManager->deleteShare($share);
				} catch (\Throwable $del) {
					$this->logger->warning('portalcheck sync enable compensation failed', ['e' => $del->getMessage()]);
				}
				$winner = $this->syncs->findByIdentityAndOrg($identityId, $orgId);
				if ($winner !== null) {
					return $winner;
				}
			}
			throw $e;
		}
		$this->audit->log($actor, 'sync.enable', 'sync', (int)$b->getId(), ['uid' => $ncUid, 'identity' => $identityId, 'org' => $orgId, 'state' => $state]);
		return $b;
	}

	private function createOrgShare(string $actor, Folder $folder, string $ncUid, string $perms): IShare
	{
		try {
			$share = $this->shareManager->newShare();
			$share->setNode($folder);
			$share->setShareType(IShare::TYPE_USER);
			$share->setSharedWith($ncUid);
			$share->setSharedBy($actor);
			// reshare bit is never set (D-B07/NN-02) — mask is rw or ro only
			$share->setPermissions(self::permsMask($perms));
			return $this->shareManager->createShare($share);
		} catch (ApiException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ApiException(403, 'NO_SHARE_PERM', 'actor lacks share permission on the org folder: ' . $e->getMessage());
		}
	}

	// ── verify (read-only w.r.t. shares, D-B18) ─────────────────────

	/**
	 * Live verification — the only state authority (D-B06). Rate-capped to
	 * one live verify per binding per VERIFY_THROTTLE_SECONDS unless forced;
	 * the stored state is a cache for list views between verifies.
	 */
	public function verify(SyncBinding $b, bool $force = false): SyncBinding
	{
		$from = $b->getState();
		if ($b->isTerminal() || $from === SyncBinding::ST_DISABLED) {
			return $b;
		}
		$last = $b->getLastVerifyAt();
		if (!$force && $last !== null && $last > (new \DateTimeImmutable())->modify('-' . self::VERIFY_THROTTLE_SECONDS . ' seconds')) {
			return $b;
		}
		$identity = $this->identities->find((int)$b->getIdentityId());
		$uid = $identity?->getNcUid();
		$new = $from;
		$share = null;
		$node = null;
		if ($uid === null || $uid === '' || ($user = $this->user($uid)) === null || !$user->isEnabled()) {
			$new = SyncBinding::ST_BROKEN_USER;
		} elseif ($this->door->isStaff($uid)) {
			// bound uid became staff-admitted post-attach — flag, never auto-revoke (§9.4)
			$new = SyncBinding::ST_STAFF_CONFLICT;
		} else {
			$node = $this->orgFolderNode((int)$b->getOrgId());
			if ($node === null) {
				$new = SyncBinding::ST_BROKEN_SHARE;
			} else {
				$share = $this->liveShare($b, $uid, $node);
				if ($share === null || (int)$share->getNodeId() !== (int)$node->getId()) {
					$new = SyncBinding::ST_BROKEN_SHARE;
				} elseif ($share->getPermissions() !== self::permsMask($b->getPerms()) || ($share->getPermissions() & Constants::PERMISSION_SHARE) !== 0) {
					$new = SyncBinding::ST_BROKEN_PERMS;
				} else {
					$new = $share->getStatus() === IShare::STATUS_PENDING ? SyncBinding::ST_PENDING : SyncBinding::ST_ACTIVE;
				}
			}
		}
		$b->setState($new);
		$b->setLastVerifyAt(new \DateTimeImmutable());
		$b->setUpdatedAt(new \DateTimeImmutable());
		if ($share !== null && $share->getId() !== $b->getShareId()) {
			// platform truth: re-anchor the row to the live share id
			$b->setShareId($share->getId());
			$b->setShareNodeId((int)$share->getNodeId());
		}
		$this->syncs->update($b);
		$healthy = [SyncBinding::ST_ACTIVE, SyncBinding::ST_PENDING, SyncBinding::ST_DISABLED];
		if ($new !== $from && !in_array($new, $healthy, true)) {
			$this->audit->log('system:sync', 'sync.verify_break', 'sync', (int)$b->getId(), ['from' => $from, 'to' => $new, 'identity' => (int)$b->getIdentityId(), 'org' => (int)$b->getOrgId(), 'uid' => $uid]);
			$this->notifyBreak($b);
		}
		return $b;
	}

	/** NH-01: staff bell on verify_break transitions — content-free, orgId only. */
	private function notifyBreak(SyncBinding $b): void
	{
		if ($this->notify === null) {
			return;
		}
		$link = $this->urls->linkToRouteAbsolute('portalcheck.page.index') . '?view=orgs';
		foreach ($this->notify->staffRecipients((int)$b->getOrgId()) as $uid) {
			$this->notify->staffNotify($uid, 'portal_sync_break', (int)$b->getOrgId(), $link);
		}
	}

	// ── revoke / repair / perms ─────────────────────────────────────

	/** Revoke a binding — idempotent; tolerates already-gone shares (US-S05). */
	public function revoke(string $actor, int $bindingId): SyncBinding
	{
		$b = $this->mustBinding($bindingId);
		if ($b->isTerminal()) {
			return $b;
		}
		$this->dropShare($b);
		$b->setState(SyncBinding::ST_REVOKED);
		$b->setUpdatedAt(new \DateTimeImmutable());
		$this->syncs->update($b);
		$this->audit->log($actor, 'sync.revoke', 'sync', $bindingId, ['identity' => (int)$b->getIdentityId(), 'org' => (int)$b->getOrgId()]);
		return $b;
	}

	private function dropShare(SyncBinding $b): void
	{
		$identity = $this->identities->find((int)$b->getIdentityId());
		$uid = $identity?->getNcUid();
		$node = $this->orgFolderNode((int)$b->getOrgId());
		$share = $uid !== null && $uid !== '' ? $this->liveShare($b, $uid, $node) : null;
		if ($share !== null) {
			try {
				$this->shareManager->deleteShare($share);
			} catch (\Throwable $e) {
				$this->logger->warning('portalcheck sync share delete failed', ['binding' => (int)$b->getId(), 'e' => $e->getMessage()]);
			}
		}
	}

	/** One-click repair for broken_share/broken_perms (US-S04). */
	public function repair(string $actor, int $bindingId): SyncBinding
	{
		$b = $this->mustBinding($bindingId);
		$state = $b->getState();
		if ($state === SyncBinding::ST_BROKEN_USER || $state === SyncBinding::ST_STAFF_CONFLICT) {
			throw new ApiException(400, 'SYNC_UID_UNKNOWN', 'account must be fixed in user administration first');
		}
		if (!in_array($state, [SyncBinding::ST_BROKEN_SHARE, SyncBinding::ST_BROKEN_PERMS], true)) {
			return $b; // active/pending need no repair
		}
		$identity = $this->identities->find((int)$b->getIdentityId());
		$uid = $identity?->getNcUid();
		$user = $uid !== null && $uid !== '' ? $this->user($uid) : null;
		if ($uid === null || $uid === '' || $user === null || !$user->isEnabled()) {
			throw new ApiException(400, 'SYNC_UID_UNKNOWN', 'account is missing or disabled');
		}
		$node = $this->orgFolderNode((int)$b->getOrgId());
		if ($node === null) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'org folder unavailable');
		}
		$share = $this->liveShare($b, $uid, $node);
		if ($share === null) {
			$share = $this->createOrgShare($actor, $node, $uid, $b->getPerms());
		} else {
			// reuse the re-appeared share; normalise its perms in place (US-S04 AC-3)
			$want = self::permsMask($b->getPerms());
			if ($share->getPermissions() !== $want || ($share->getPermissions() & Constants::PERMISSION_SHARE) !== 0) {
				try {
					$share->setPermissions($want);
					$share = $this->shareManager->updateShare($share);
				} catch (\Throwable $e) {
					throw new ApiException(403, 'NO_SHARE_PERM', 'cannot normalise share permissions: ' . $e->getMessage());
				}
			}
		}
		$b->setShareId($share->getId());
		$b->setShareNodeId((int)$node->getId());
		$b->setSharedBy($actor);
		$b->setState($share->getStatus() === IShare::STATUS_PENDING ? SyncBinding::ST_PENDING : SyncBinding::ST_ACTIVE);
		$b->setLastVerifyAt(new \DateTimeImmutable());
		$b->setUpdatedAt(new \DateTimeImmutable());
		$this->syncs->update($b);
		$this->audit->log($actor, 'sync.repair', 'sync', $bindingId, ['state' => $b->getState()]);
		return $b;
	}

	/** rw ⇄ ro — updates the live share in place (no resync storm, US-S10 AC-2). */
	public function setPerms(string $actor, int $bindingId, string $perms): SyncBinding
	{
		if (!in_array($perms, [SyncBinding::PERMS_RW, SyncBinding::PERMS_RO], true)) {
			throw new ValidationException('VALIDATION_FAILED', ['perms' => 'invalid']);
		}
		$b = $this->mustBinding($bindingId);
		if ($b->isTerminal()) {
			throw new ValidationException('VALIDATION_FAILED', ['perms' => 'binding_revoked']);
		}
		$identity = $this->identities->find((int)$b->getIdentityId());
		$uid = $identity?->getNcUid();
		$node = $this->orgFolderNode((int)$b->getOrgId());
		$share = $uid !== null && $uid !== '' ? $this->liveShare($b, $uid, $node) : null;
		if ($share !== null) {
			try {
				$share->setPermissions(self::permsMask($perms));
				$this->shareManager->updateShare($share);
			} catch (\Throwable $e) {
				throw new ApiException(403, 'NO_SHARE_PERM', 'cannot update share permissions: ' . $e->getMessage());
			}
		}
		$b->setPerms($perms);
		$b->setUpdatedAt(new \DateTimeImmutable());
		$this->syncs->update($b);
		$this->audit->log($actor, 'sync.perms', 'sync', $bindingId, ['perms' => $perms]);
		return $b;
	}

	// ── read models ─────────────────────────────────────────────────

	/**
	 * Org-detail list: every binding of the org with a live (rate-capped)
	 * verify — D-B06 read-through.
	 * @return list<array{bindingId:int,identityId:int,ncUid:?string,ncDisplay:?string,ncUidMismatch:bool,perms:string,state:string,verifiedAt:?string}>
	 */
	public function listForOrg(int $orgId): array
	{
		$out = [];
		foreach ($this->syncs->findByOrg($orgId) as $b) {
			if ($b->isTerminal()) {
				continue;
			}
			$b = $this->verify($b);
			$identity = $this->identities->find((int)$b->getIdentityId());
			$uid = $identity?->getNcUid();
			$user = $uid !== null && $uid !== '' ? $this->user($uid) : null;
			$out[] = [
				'bindingId' => (int)$b->getId(),
				'identityId' => (int)$b->getIdentityId(),
				'ncUid' => $uid,
				'ncDisplay' => $user?->getDisplayName(),
				'ncUidMismatch' => (bool)($identity?->getNcUidMismatch()),
				'perms' => $b->getPerms(),
				'state' => $b->getState(),
				'verifiedAt' => $b->getLastVerifyAt()?->format('c'),
			];
		}
		return $out;
	}

	/** Email-match suggestion for the setup dialog (D-B21). */
	public function suggestFor(Identity $identity): ?array
	{
		$email = IdentityService::normalizeEmail($identity->getEmail());
		if ($email === '') {
			return null;
		}
		foreach ($this->userManager->search($email, 10) as $u) {
			if (IdentityService::normalizeEmail((string)($u->getEMailAddress() ?? '')) === $email && $u->isEnabled() && !$this->door->isStaff($u->getUID())) {
				return ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName(), 'email' => $u->getEMailAddress()];
			}
		}
		return null;
	}

	/**
	 * CSV plan classifier for the nc_uid column (US-S08/MH-07): maps a
	 * candidate uid to its bucket — 'ok' or the blocked reason.
	 */
	public function classifyNcUid(string $ncUid, string $identityEmail): string
	{
		$ncUid = trim($ncUid);
		if ($ncUid === '') {
			return 'ok'; // column empty — no sync step
		}
		if (!$this->door->isRestricted()) {
			return 'door_open';
		}
		$user = $this->user($ncUid);
		if ($user === null) {
			return 'uid_unknown';
		}
		if ($this->door->isStaff($ncUid)) {
			return 'staff_uid';
		}
		if (!$user->isEnabled()) {
			return 'uid_disabled';
		}
		$other = $this->identities->findByNcUid($ncUid);
		if ($other !== null && IdentityService::normalizeEmail($other->getEmail()) !== IdentityService::normalizeEmail($identityEmail)) {
			return 'uid_shared';
		}
		$ncMail = IdentityService::normalizeEmail((string)($user->getEMailAddress() ?? ''));
		if ($ncMail === '' || $ncMail !== IdentityService::normalizeEmail($identityEmail)) {
			return 'uid_mismatch';
		}
		return 'ok';
	}

	// ── cascades ────────────────────────────────────────────────────

	/**
	 * Org offboard sweep (US-S06/ZF-03): revoke every non-terminal binding
	 * of the org. Failures are logged per binding and surfaced — never
	 * silently swallowed, never abort the cascade.
	 * @return array{processed:int,failed:int}
	 */
	public function cascadeOffboard(string $actor, int $orgId): array
	{
		$processed = 0;
		$failed = 0;
		foreach ($this->syncs->findByOrg($orgId) as $b) {
			if ($b->isTerminal()) {
				continue;
			}
			try {
				$this->revoke($actor, (int)$b->getId());
				$processed++;
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning('portalcheck sync offboard cascade failed', ['binding' => (int)$b->getId(), 'e' => $e->getMessage()]);
			}
		}
		return ['processed' => $processed, 'failed' => $failed];
	}

	/**
	 * GDPR erase cascade (US-S07): revoke share best-effort → delete rows →
	 * clear nc_uid. The attached NC user is NEVER deleted/disabled (D-B08/NN-01).
	 */
	public function cascadeErase(string $actor, int $identityId): void
	{
		$identity = $this->identities->find($identityId);
		foreach ($this->syncs->findByIdentity($identityId) as $b) {
			if (!$b->isTerminal()) {
				try {
					$this->revoke($actor, (int)$b->getId());
				} catch (\Throwable $e) {
					// AS-05: erase is never held hostage by filesystem errors
					$this->logger->warning('portalcheck sync erase revoke failed', ['binding' => (int)$b->getId(), 'e' => $e->getMessage()]);
				}
			}
		}
		$this->syncs->deleteByIdentity($identityId);
		if ($identity !== null && $identity->getNcUid() !== null && $identity->getNcUid() !== '') {
			$identity->setNcUid(null);
			$identity->setNcUidOrigin(null);
			$identity->setNcUidMismatch(false);
			$this->identities->update($identity);
			$this->audit->log($actor, 'sync.unbind', 'identity', $identityId, ['cascade' => 'erase']);
		}
	}

	/**
	 * Member-removal cascade (MH-15): revoke the member's binding BEFORE the
	 * membership row drops — same ordering rule as org offboard.
	 */
	public function cascadeMemberRemove(string $actor, int $identityId, int $orgId): void
	{
		$b = $this->syncs->findByIdentityAndOrg($identityId, $orgId);
		if ($b !== null && !$b->isTerminal()) {
			$this->revoke($actor, (int)$b->getId());
		}
	}

	// ── reconcile + diagnostics ─────────────────────────────────────

	/**
	 * Reconcile batch (≤6 h job): verify every non-terminal binding.
	 * Strictly read-only on shares (NN-11) — computes and caches state only.
	 */
	public function reconcile(int $afterId, int $batch): array
	{
		$processed = 0;
		$lastId = $afterId;
		$counts = ['ok' => 0, 'broken' => 0, 'conflict' => 0];
		foreach ($this->syncs->findPage($afterId, $batch) as $b) {
			$lastId = (int)$b->getId();
			if ($b->isTerminal()) {
				continue;
			}
			$state = $this->verify($b, true)->getState();
			$processed++;
			if (in_array($state, [SyncBinding::ST_ACTIVE, SyncBinding::ST_PENDING, SyncBinding::ST_DISABLED], true)) {
				$counts['ok']++;
			} elseif ($state === SyncBinding::ST_STAFF_CONFLICT) {
				$counts['conflict']++;
			} else {
				$counts['broken']++;
			}
		}
		$this->logger->info('portalcheck sync reconcile run', $counts + ['processed' => $processed]);
		return ['lastId' => $lastId, 'processed' => $processed, 'counts' => $counts];
	}

	/** Diagnostics view-model (US-S09). */
	public function diagnosticsSnapshot(): array
	{
		$bindings = $this->syncs->countNonTerminal();
		$staffBound = 0;
		foreach ($this->syncs->findNonTerminal() as $b) {
			$identity = $this->identities->find((int)$b->getIdentityId());
			$uid = $identity?->getNcUid();
			if ($uid !== null && $uid !== '' && $this->door->isStaff($uid)) {
				$staffBound++;
			}
		}
		$broken = $this->syncs->countByState(SyncBinding::ST_BROKEN_USER)
			+ $this->syncs->countByState(SyncBinding::ST_BROKEN_SHARE)
			+ $this->syncs->countByState(SyncBinding::ST_BROKEN_PERMS);
		return [
			'bindings' => $bindings,
			'doorOpen' => !$this->door->isRestricted(),
			'staffBound' => $staffBound,
			'broken' => $broken,
		];
	}
}

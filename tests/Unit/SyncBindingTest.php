<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Identity;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\Member;
use OCA\KanzleiCheck\Db\MemberMapper;
use OCA\KanzleiCheck\Db\Org;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Db\SyncBinding;
use OCA\KanzleiCheck\Db\SyncBindingMapper;
use OCA\KanzleiCheck\Db\Upload;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\DocumentService;
use OCA\KanzleiCheck\Service\ImportService;
use OCA\KanzleiCheck\Service\OrgService;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\SyncBindingService;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SyncBindingService branch matrix (nc-user-binding spec): attach guards,
 * share-first enable, verify state machine, revoke idempotence, perms mask,
 * door refusal, CSV classifier, upload integrity gate.
 */
class SyncBindingTest extends TestCase
{
	private SyncBindingMapper&MockObject $syncs;
	private IdentityMapper&MockObject $identities;
	private MemberMapper&MockObject $members;
	private OrgMapper&MockObject $orgs;
	private DocumentService&MockObject $docs;
	private AccessDoorService&MockObject $door;
	private IUserManager&MockObject $userManager;
	private IShareManager&MockObject $shares;
	private AuditService&MockObject $audit;
	private IDBConnection&MockObject $db;
	private SyncBindingService $svc;

	protected function setUp(): void
	{
		$this->syncs = $this->createMock(SyncBindingMapper::class);
		$this->identities = $this->createMock(IdentityMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->orgs = $this->createMock(OrgMapper::class);
		$this->docs = $this->createMock(DocumentService::class);
		$this->door = $this->createMock(AccessDoorService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->shares = $this->createMock(IShareManager::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$urls = $this->createMock(IURLGenerator::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $this->orgs,
			$this->docs, $this->door, $this->userManager, $this->shares,
			$this->audit, $this->db, $urls, $logger, null,
		);
	}

	private function identity(int $id = 7, string $email = 'mandant@x.de', ?string $ncUid = null): Identity
	{
		$i = new Identity();
		$i->setId($id);
		$i->setEmail($email);
		$i->setDisplayName('Mandant');
		$i->setStatus('active');
		$i->setNcUid($ncUid);
		return $i;
	}

	private function user(string $uid, string $email = 'mandant@x.de', bool $enabled = true): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('getEMailAddress')->willReturn($email);
		$u->method('isEnabled')->willReturn($enabled);
		$u->method('getDisplayName')->willReturn('Mandant N');
		return $u;
	}

	private function binding(int $id = 3, string $state = SyncBinding::ST_ACTIVE, string $perms = 'rw'): SyncBinding
	{
		$b = new SyncBinding();
		$b->setId($id);
		$b->setIdentityId(7);
		$b->setOrgId(9);
		$b->setShareId('42');
		$b->setShareNodeId(100);
		$b->setSharedBy('admin');
		$b->setPerms($perms);
		$b->setState($state);
		$b->setLastVerifyAt((new \DateTimeImmutable())->modify('-1 hour'));
		return $b;
	}

	private function code(callable $fn): string
	{
		try {
			$fn();
			return 'none';
		} catch (ApiException $e) {
			return $e->getErrorCode();
		} catch (NotFoundException) {
			return 'NOT_FOUND';
		}
	}

	// ── attach guards (US-S01) ──

	public function testAttachHappyPath(): void
	{
		$i = $this->identity();
		$this->identities->method('find')->willReturn($i);
		$this->userManager->method('userExists')->with('max')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->identities->method('findAllByNcUid')->willReturn([]);
		$this->identities->expects($this->once())->method('update')->with($i);
		$out = $this->svc->attach('admin', 7, 'max');
		$this->assertSame('max', $out->getNcUid());
		$this->assertSame('attach', $out->getNcUidOrigin());
		$this->assertFalse($out->getNcUidMismatch());
	}

	public function testAttachUnknownUid(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(false);
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $this->svc->attach('admin', 7, 'ghost')));
	}

	public function testAttachOpenDoorRejectedBeforeStaffLeg(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(false);
		// under an Open door every uid resolves as staff — the door error must
		// fire anyway (D-B05), so isStaff is never consulted
		$this->door->expects($this->never())->method('isStaff');
		$this->assertSame('SYNC_DOOR_OPEN', $this->code(fn() => $this->svc->attach('admin', 7, 'max')));
	}

	public function testAttachStaffUidRejected(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->with('boss')->willReturn(true);
		$this->assertSame('SYNC_UID_IS_STAFF', $this->code(fn() => $this->svc->attach('admin', 7, 'boss')));
	}

	public function testAttachDisabledUid(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max', 'mandant@x.de', false));
		$this->assertSame('SYNC_UID_DISABLED', $this->code(fn() => $this->svc->attach('admin', 7, 'max')));
	}

	public function testAttachAlreadyBound(): void
	{
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('moritz'));
		$this->assertSame('SYNC_ALREADY_BOUND', $this->code(fn() => $this->svc->attach('admin', 7, 'moritz')));
	}

	public function testAttachSharedUidRequiresFlag(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('shared'));
		$other = $this->identity(8, 'other@x.de', 'shared');
		$this->identities->method('findAllByNcUid')->willReturn([$other]);
		$this->assertSame('SYNC_UID_SHARED', $this->code(fn() => $this->svc->attach('admin', 7, 'shared')));
		// explicit flag binds + audit detail marks shared_uid
		$this->identities->expects($this->once())->method('update');
		$out = $this->svc->attach('admin', 7, 'shared', false, true);
		$this->assertSame('shared', $out->getNcUid());
	}

	public function testAttachDuplicateNcUidRowsStillGates(): void
	{
		// regression: acceptShared binds leave multiple identities on one uid —
		// lookup must not throw >1-row and must still gate the next attach
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('shared'));
		$this->identities->method('findAllByNcUid')->willReturn([
			$this->identity(8, 'other@x.de', 'shared'),
			$this->identity(9, 'other2@x.de', 'shared'),
		]);
		$this->assertSame('SYNC_UID_SHARED', $this->code(fn() => $this->svc->attach('admin', 7, 'shared')));
		$this->identities->expects($this->once())->method('update');
		$out = $this->svc->attach('admin', 7, 'shared', false, true);
		$this->assertSame('shared', $out->getNcUid());
	}

	public function testAttachEmailMismatchGateAndDurableFlag(): void
	{
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max', 'anders@x.de'));
		$this->assertSame('SYNC_EMAIL_MISMATCH', $this->code(fn() => $this->svc->attach('admin', 7, 'max')));
		$out = $this->svc->attach('admin', 7, 'max', true, false);
		$this->assertTrue($out->getNcUidMismatch()); // durable (D-B16)
	}

	public function testAttachUidWithoutNcEmailCountsAsMismatch(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max', ''));
		$this->assertSame('SYNC_EMAIL_MISMATCH', $this->code(fn() => $this->svc->attach('admin', 7, 'max')));
	}

	// ── unbind ──

	public function testUnbindRefusedWithLiveBindings(): void
	{
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->syncs->method('findByIdentity')->willReturn([$this->binding()]);
		$this->assertSame('SYNC_BINDINGS_LIVE', $this->code(fn() => $this->svc->unbind('admin', 7)));
	}

	public function testUnbindClearsAfterTerminalBindings(): void
	{
		$i = $this->identity(7, 'mandant@x.de', 'max');
		$this->identities->method('find')->willReturn($i);
		$this->syncs->method('findByIdentity')->willReturn([$this->binding(3, SyncBinding::ST_REVOKED)]);
		$this->identities->expects($this->once())->method('update')->with($i);
		$this->svc->unbind('admin', 7);
		$this->assertNull($i->getNcUid());
		$this->assertFalse($i->getNcUidMismatch());
	}

	// ── enable (share-first, ADR-Z01) ──

	private function orgReady(): Org
	{
		$org = new Org();
		$org->setId(9);
		$org->setName('Mandant A');
		$org->setState('active');
		$org->setFolderId(100);
		return $org;
	}

	public function testEnableUnboundIdentity(): void
	{
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity());
		$m = new Member();
		$this->members->method('findByIdentityAndOrg')->willReturn($m);
		$this->assertSame('SYNC_NOT_BOUND', $this->code(fn() => $this->svc->enable('admin', 9, 7)));
	}

	public function testEnableOffboardedOrg(): void
	{
		$org = $this->orgReady();
		$org->setState('offboarded');
		$this->orgs->method('find')->willReturn($org);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->assertSame('SYNC_ORG_OFFBOARDED', $this->code(fn() => $this->svc->enable('admin', 9, 7)));
	}

	public function testEnableIdempotentOnActiveBinding(): void
	{
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$existing = $this->binding();
		$this->syncs->method('findByIdentityAndOrg')->willReturn($existing);
		// no share creation on idempotent return
		$this->shares->expects($this->never())->method('createShare');
		$this->assertSame($existing, $this->svc->enable('admin', 9, 7));
	}

	public function testEnableCreatesShareWithReshareOff(): void
	{
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('orgFolder')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('newShare')->willReturn($share);
		$share->expects($this->once())->method('setPermissions')->with(1 | 2 | 4 | 8); // rw, no reshare
		$this->shares->method('createShare')->willReturn($share);
		$this->syncs->method('insert')->willReturnCallback(fn($b) => $b);
		$b = $this->svc->enable('admin', 9, 7);
		$this->assertSame(SyncBinding::ST_ACTIVE, $b->getState());
		$this->assertSame('42', $b->getShareId());
		$this->assertSame(100, $b->getShareNodeId());
	}

	public function testEnablePendingAcceptState(): void
	{
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('orgFolder')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_PENDING);
		$share->method('getId')->willReturn('42');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturn($share);
		$this->syncs->method('insert')->willReturnCallback(fn($b) => $b);
		$this->assertSame(SyncBinding::ST_PENDING, $this->svc->enable('admin', 9, 7)->getState());
	}

	public function testEnableDisabledUidRejected(): void
	{
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max', 'mandant@x.de', false));
		$this->assertSame('SYNC_UID_DISABLED', $this->code(fn() => $this->svc->enable('admin', 9, 7)));
	}

	// ── verify state machine ──

	public function testVerifyDetectsDeletedUser(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn(null);
		$this->syncs->expects($this->once())->method('update')->with($b);
		$out = $this->svc->verify($b, true);
		$this->assertSame(SyncBinding::ST_BROKEN_USER, $out->getState());
	}

	public function testVerifyDetectsStaffConflict(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(true);
		$this->syncs->expects($this->once())->method('update');
		$this->assertSame(SyncBinding::ST_STAFF_CONFLICT, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyDetectsMissingShare(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$org = $this->orgReady();
		$this->orgs->method('find')->willReturn($org);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$this->shares->method('getShareById')->willThrowException(new \OCP\Share\Exceptions\ShareNotFound());
		$this->shares->method('getSharedWith')->willReturn([]);
		$this->assertSame(SyncBinding::ST_BROKEN_SHARE, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyDetectsPermDrift(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$org = $this->orgReady();
		$this->orgs->method('find')->willReturn($org);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('getPermissions')->willReturn(31); // reshare bit appeared
		$share->method('getId')->willReturn('42');
		$this->shares->method('getShareById')->willReturn($share);
		$this->assertSame(SyncBinding::ST_BROKEN_PERMS, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyHealthyStaysActive(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$org = $this->orgReady();
		$this->orgs->method('find')->willReturn($org);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('getPermissions')->willReturn(15);
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('getShareById')->willReturn($share);
		$this->assertSame(SyncBinding::ST_ACTIVE, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyRateCapSkipsRecentCheck(): void
	{
		$b = $this->binding();
		$b->setLastVerifyAt(new \DateTimeImmutable()); // just verified
		$this->userManager->expects($this->never())->method('get');
		$this->assertSame(SyncBinding::ST_ACTIVE, $this->svc->verify($b, false)->getState());
	}

	public function testVerifyIsReadOnlyOnShares(): void
	{
		// NN-11: verify may never call IShareManager mutations
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn(null); // broken_user path
		foreach (['createShare', 'updateShare', 'deleteShare', 'newShare'] as $m) {
			$this->shares->expects($this->never())->method($m);
		}
		$this->svc->verify($b, true);
		$this->addToAssertionCount(1);
	}

	// ── revoke / repair / perms ──

	public function testRevokeIdempotentOnTerminal(): void
	{
		$b = $this->binding(3, SyncBinding::ST_REVOKED);
		$this->syncs->method('find')->willReturn($b);
		$this->shares->expects($this->never())->method('deleteShare');
		$this->assertSame($b, $this->svc->revoke('admin', 3));
	}

	public function testRepairRefusesBrokenUser(): void
	{
		$b = $this->binding(3, SyncBinding::ST_BROKEN_USER);
		$this->syncs->method('find')->willReturn($b);
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $this->svc->repair('admin', 3)));
	}

	public function testRepairCreatesShareOnBrokenShare(): void
	{
		$b = $this->binding(3, SyncBinding::ST_BROKEN_SHARE);
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$this->shares->method('getShareById')->willThrowException(new \OCP\Share\Exceptions\ShareNotFound());
		$this->shares->method('getSharedWith')->willReturn([]);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('77');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->expects($this->once())->method('createShare')->willReturn($share);
		$this->syncs->expects($this->once())->method('update')->with($b);
		$out = $this->svc->repair('admin', 3);
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
		$this->assertSame('77', $out->getShareId());
	}

	public function testRepairNormalizesDriftedShareInPlace(): void
	{
		$b = $this->binding(3, SyncBinding::ST_BROKEN_PERMS);
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		// externally re-appeared share with wrong perms → reuse + normalise
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('getPermissions')->willReturn(31); // reshare crept in
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('getShareById')->willReturn($share);
		$share->expects($this->once())->method('setPermissions')->with(15);
		$this->shares->expects($this->once())->method('updateShare')->willReturn($share);
		$this->shares->expects($this->never())->method('createShare');
		$this->assertSame(SyncBinding::ST_ACTIVE, $this->svc->repair('admin', 3)->getState());
	}

	public function testRevokeDeletesShareAndTerminates(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('deleteShare')->with($share);
		$this->syncs->expects($this->once())->method('update')->with($b);
		$this->assertSame(SyncBinding::ST_REVOKED, $this->svc->revoke('admin', 3)->getState());
	}

	public function testRevokeToleratesGoneShare(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$this->shares->method('getShareById')->willThrowException(new \OCP\Share\Exceptions\ShareNotFound());
		$this->shares->method('getSharedWith')->willReturn([]);
		$this->assertSame(SyncBinding::ST_REVOKED, $this->svc->revoke('admin', 3)->getState());
	}

	public function testSetPermsUpdatesShareAndRow(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('setPermissions')->willReturnSelf();
		$this->shares->method('getShareById')->willReturn($share);
		$share->expects($this->once())->method('setPermissions')->with(1); // rw → ro
		$this->shares->expects($this->once())->method('updateShare');
		$this->syncs->expects($this->once())->method('update')->with($b);
		$this->assertSame('ro', $this->svc->setPerms('admin', 3, 'ro')->getPerms());
	}

	public function testSetPermsRejectsRevoked(): void
	{
		$b = $this->binding(3, SyncBinding::ST_REVOKED);
		$this->syncs->method('find')->willReturn($b);
		try {
			$this->svc->setPerms('admin', 3, 'ro');
			$this->fail('expected ValidationException');
		} catch (\OCA\KanzleiCheck\Exception\ValidationException $e) {
			$this->assertSame(['perms' => 'binding_revoked'], $e->getFields());
		}
	}

	public function testVerifyNodeIdMismatchIsBrokenShare(): void
	{
		// NN-08: a share of a DIFFERENT node does not satisfy the binding
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(999); // wrong node
		$this->shares->method('getShareById')->willReturn($share);
		$this->assertSame(SyncBinding::ST_BROKEN_SHARE, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyRoPermsDriftDetected(): void
	{
		$b = $this->binding(3, SyncBinding::ST_ACTIVE, 'ro');
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('getPermissions')->willReturn(15); // expected ro=1, found rw=15
		$share->method('getId')->willReturn('42');
		$this->shares->method('getShareById')->willReturn($share);
		$this->assertSame(SyncBinding::ST_BROKEN_PERMS, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyReanchorsToReappearedShare(): void
	{
		// stored share id gone, share reappeared externally (D-B06 reuse)
		$b = $this->binding(3, SyncBinding::ST_BROKEN_SHARE);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$this->shares->method('getShareById')->willThrowException(new \OCP\Share\Exceptions\ShareNotFound());
		$newShare = $this->createMock(IShare::class);
		$newShare->method('getNodeId')->willReturn(100);
		$newShare->method('getPermissions')->willReturn(15);
		$newShare->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$newShare->method('getId')->willReturn('88'); // different id than stored 42
		$this->shares->method('getSharedWith')->willReturn([$newShare]);
		$this->syncs->expects($this->once())->method('update')->with($b);
		$out = $this->svc->verify($b, true);
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
		$this->assertSame('88', $out->getShareId()); // re-anchored to live id
	}

	public function testVerifyForcedBypassesRateCap(): void
	{
		$b = $this->binding();
		$b->setLastVerifyAt(new \DateTimeImmutable()); // verified seconds ago
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn(null); // user deleted
		$this->syncs->expects($this->once())->method('update');
		// force=true → the 60 s cap is bypassed and the break is detected
		$this->assertSame(SyncBinding::ST_BROKEN_USER, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyDisabledBindingSkipped(): void
	{
		$b = $this->binding(3, SyncBinding::ST_DISABLED);
		$this->userManager->expects($this->never())->method('get');
		$this->syncs->expects($this->never())->method('update');
		$this->assertSame(SyncBinding::ST_DISABLED, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyDisabledUserBecomesBrokenUser(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max', 'mandant@x.de', false));
		$this->assertSame(SyncBinding::ST_BROKEN_USER, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyWritesBreakAudit(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn(null);
		$this->audit->expects($this->once())->method('log')
			->with('system:sync', 'sync.verify_break', 'sync', 3);
		$this->svc->verify($b, true);
	}

	public function testVerifyWithoutLastVerifyRuns(): void
	{
		$b = $this->binding();
		$b->setLastVerifyAt(null); // never verified — rate cap cannot short-circuit
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn(null);
		$this->syncs->expects($this->once())->method('update');
		$this->assertSame(SyncBinding::ST_BROKEN_USER, $this->svc->verify($b, false)->getState());
	}

	public function testRepairOnCorrectPermsJustActivates(): void
	{
		// stale broken_perms state, share already correct → no updateShare
		$b = $this->binding(3, SyncBinding::ST_BROKEN_PERMS);
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('getPermissions')->willReturn(15); // already correct
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->never())->method('updateShare');
		$this->shares->expects($this->never())->method('createShare');
		$this->assertSame(SyncBinding::ST_ACTIVE, $this->svc->repair('admin', 3)->getState());
	}

	public function testRepairOnActiveIsIdempotent(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->shares->expects($this->never())->method('createShare');
		$this->assertSame($b, $this->svc->repair('admin', 3));
	}

	public function testEnableRevivesRevokedRow(): void
	{
		// revoked row still holds the unique key — enable updates it in place
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$revoked = $this->binding(3, SyncBinding::ST_REVOKED);
		$this->syncs->method('findByIdentityAndOrg')->willReturn($revoked);
		$this->docs->method('rootHealthy')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('orgFolder')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('99');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturn($share);
		$this->syncs->expects($this->once())->method('update')->willReturnCallback(fn($b) => $b);
		$this->syncs->expects($this->never())->method('insert');
		$out = $this->svc->enable('admin', 9, 7);
		$this->assertSame(3, (int)$out->getId());
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
		$this->assertSame('99', $out->getShareId());
	}

	public function testSetPermsWithoutLiveShareUpdatesRowOnly(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$this->shares->method('getShareById')->willThrowException(new \OCP\Share\Exceptions\ShareNotFound());
		$this->shares->method('getSharedWith')->willReturn([]);
		$this->shares->expects($this->never())->method('updateShare');
		$this->syncs->expects($this->once())->method('update');
		$this->assertSame('ro', $this->svc->setPerms('admin', 3, 'ro')->getPerms());
	}

	public function testSuggestForSkipsStaffAndMismatch(): void
	{
		$identity = $this->identity(7, 'mandant@x.de');
		$u1 = $this->user('staffer', 'mandant@x.de');
		$u2 = $this->user('max', 'mandant@x.de');
		$u3 = $this->user('other', 'other@x.de');
		$this->userManager->method('search')->willReturn([$u1, $u2, $u3]);
		$this->door->method('isStaff')->willReturnCallback(fn($u) => $u === 'staffer');
		$sug = $this->svc->suggestFor($identity);
		$this->assertSame('max', $sug['uid']); // staff + email-mismatch skipped
	}

	public function testSuggestForReturnsNullWithoutMatch(): void
	{
		$this->userManager->method('search')->willReturn([$this->user('x', 'other@x.de')]);
		$this->door->method('isStaff')->willReturn(false);
		$this->assertNull($this->svc->suggestFor($this->identity()));
	}

	public function testCascadeEraseLeavesNcUserUntouched(): void
	{
		// D-B08/NN-01: erase revokes shares + drops rows + clears nc_uid —
		// the NC user object is never disabled/deleted.
		$i = $this->identity(7, 'mandant@x.de', 'max');
		$this->identities->method('find')->willReturn($i);
		$b = $this->binding(3);
		$this->syncs->method('findByIdentity')->willReturn([$b]);
		$this->syncs->method('find')->willReturn($b);
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('deleteShare');
		$this->syncs->expects($this->once())->method('deleteByIdentity')->with(7);
		$this->identities->expects($this->once())->method('update')->with($i);
		$this->userManager->expects($this->never())->method('get');
		$this->svc->cascadeErase('admin', 7);
		$this->assertNull($i->getNcUid());
	}

	public function testReconcileVerifiesPageAndCounts(): void
	{
		$b1 = $this->binding(1);
		$b2 = $this->binding(2);
		$dead = $this->binding(3, SyncBinding::ST_REVOKED);
		$this->syncs->method('findPage')->with(0, 200)->willReturn([$b1, $b2, $dead]);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn(null); // both live bindings break
		$res = $this->svc->reconcile(0, 200);
		$this->assertSame(3, $res['lastId']);
		$this->assertSame(2, $res['processed']); // terminal row skipped
		$this->assertSame(2, $res['counts']['broken']);
		$this->assertSame(0, $res['counts']['conflict']);
	}

	public function testClassifySharedUidBucket(): void
	{
		$this->door->method('isRestricted')->willReturn(true);
		$this->userManager->method('get')->willReturn($this->user('shared', 'a@b.de'));
		$this->door->method('isStaff')->willReturn(false);
		// uid bound to a DIFFERENT email → shared bucket
		$this->identities->method('findAllByNcUid')->willReturn([$this->identity(9, 'other@x.de', 'shared')]);
		$this->assertSame('uid_shared', $this->svc->classifyNcUid('shared', 'a@b.de'));
	}

	public function testClassifySharedUidLaterRowMismatch(): void
	{
		// regression: with duplicate bound rows a mismatching email on ANY
		// row must classify shared — a first-row-only check misses it
		$this->door->method('isRestricted')->willReturn(true);
		$this->userManager->method('get')->willReturn($this->user('shared', 'a@b.de'));
		$this->door->method('isStaff')->willReturn(false);
		$this->identities->method('findAllByNcUid')->willReturn([
			$this->identity(9, 'a@b.de', 'shared'),
			$this->identity(10, 'other@x.de', 'shared'),
		]);
		$this->assertSame('uid_shared', $this->svc->classifyNcUid('shared', 'a@b.de'));
	}

	public function testDiagnosticsCountsBrokenStates(): void
	{
		$this->syncs->method('countNonTerminal')->willReturn(3);
		$this->syncs->method('findNonTerminal')->willReturn([]);
		$this->syncs->method('countByState')->willReturnCallback(
			fn($s) => $s === SyncBinding::ST_BROKEN_SHARE ? 2 : 1);
		$this->door->method('isRestricted')->willReturn(true);
		$diag = $this->svc->diagnosticsSnapshot();
		$this->assertSame(4, $diag['broken']); // 1 + 2 + 1 across the three broken states
	}

	public function testEnableCompensatesShareOnUniqueConflict(): void
	{
		// ADR-Z01: insert loses the race → the just-created share is deleted
		$this->orgs->method('find')->willReturn($this->orgReady());
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturnOnConsecutiveCalls(null, $this->binding(5));
		$this->docs->method('rootHealthy')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('orgFolder')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturn($share);
		$unique = new class extends \OCP\DB\Exception {
			public function getReason(): ?int { return self::REASON_UNIQUE_CONSTRAINT_VIOLATION; }
		};
		$this->syncs->method('insert')->willThrowException($unique);
		$this->shares->expects($this->once())->method('deleteShare')->with($share);
		// conflict → the winner row is returned, no orphan share remains
		$this->assertSame(5, (int)$this->svc->enable('admin', 9, 7)->getId());
	}

	public function testEnableRebindsFolderId(): void
	{
		$org = $this->orgReady();
		$org->setFolderId(55); // stale — folder was re-created
		$this->orgs->method('find')->willReturn($org);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->members->method('findByIdentityAndOrg')->willReturn(new Member());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('orgFolder')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturn($share);
		$this->orgs->expects($this->once())->method('update')->with($org); // folder id re-anchored
		$this->syncs->method('insert')->willReturnCallback(fn($b) => $b);
		$this->svc->enable('admin', 9, 7);
		$this->assertSame(100, (int)$org->getFolderId());
	}

	public function testUnbindMessageCarriesLiveCount(): void
	{
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->syncs->method('findByIdentity')->willReturn([
			$this->binding(1), $this->binding(2), $this->binding(3, SyncBinding::ST_REVOKED),
		]);
		try {
			$this->svc->unbind('admin', 7);
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('SYNC_BINDINGS_LIVE', $e->getErrorCode());
			$this->assertStringContainsString('2', $e->getMessage()); // exact remaining count
		}
	}

	public function testClassifyTrimsWhitespaceUid(): void
	{
		$this->door->method('isRestricted')->willReturn(true);
		$this->userManager->method('get')->willReturnCallback(fn($u) => $u === 'okuid' ? $this->user('okuid', 'a@b.de') : null);
		$this->door->method('isStaff')->willReturn(false);
		$this->identities->method('findAllByNcUid')->willReturn([]);
		$this->assertSame('ok', $this->svc->classifyNcUid('  okuid  ', 'a@b.de'));
	}

	public function testCascadeOffboardCountsProcessedAndFailed(): void
	{
		$ok = $this->binding(1);
		$bad = $this->binding(2);
		$this->syncs->method('findByOrg')->willReturn([$ok, $bad]);
		$this->syncs->method('find')->willReturnCallback(fn($id) => $id === 1 ? $ok : $bad);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->method('deleteShare')->willThrowException(new \RuntimeException('fs gone'));
		// deleteShare failure inside revoke is tolerated → both processed, 0 failed
		$res = $this->svc->cascadeOffboard('admin', 9);
		$this->assertSame(['processed' => 2, 'failed' => 0], $res);
	}

	public function testCascadeMemberRemoveOnlyTouchesMemberBinding(): void
	{
		$mine = $this->binding(3);
		$this->syncs->method('findByIdentityAndOrg')->willReturn($mine);
		$this->syncs->method('find')->willReturn($mine);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('deleteShare');
		$this->svc->cascadeMemberRemove('admin', 7, 9);
		$this->assertSame(SyncBinding::ST_REVOKED, $mine->getState());
	}

	public function testDiagnosticsCountsStaffBound(): void
	{
		$b1 = $this->binding(1);
		$b2 = $this->binding(2);
		$b2->setIdentityId(8);
		$this->syncs->method('countNonTerminal')->willReturn(2);
		$this->syncs->method('findNonTerminal')->willReturn([$b1, $b2]);
		$this->identities->method('find')->willReturnCallback(
			fn($id) => $id === 7 ? $this->identity(7, 'a@x.de', 'staffer') : $this->identity(8, 'b@x.de', 'client'));
		$this->syncs->method('countByState')->willReturn(0);
		$this->door->method('isStaff')->willReturnCallback(fn($u) => $u === 'staffer');
		$this->door->method('isRestricted')->willReturn(true);
		$diag = $this->svc->diagnosticsSnapshot();
		$this->assertSame(1, $diag['staffBound']); // bound uid admitted as staff → FAIL finding
		$this->assertFalse($diag['doorOpen']);
	}

	public function testListForOrgSkipsTerminalAndLiveVerifies(): void
	{
		$live = $this->binding(1);
		$live->setLastVerifyAt((new \DateTimeImmutable())->modify('-2 hours'));
		$dead = $this->binding(2, SyncBinding::ST_REVOKED);
		$this->syncs->method('findByOrg')->willReturn([$live, $dead]);
		$this->identities->method('find')->willReturn($this->identity(7, 'mandant@x.de', 'max'));
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgReady());
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(100);
		$this->docs->method('findNodeById')->willReturn($folder);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(100);
		$share->method('getPermissions')->willReturn(15);
		$share->method('getStatus')->willReturn(IShare::STATUS_ACCEPTED);
		$share->method('getId')->willReturn('42');
		$this->shares->method('getShareById')->willReturn($share);
		$rows = $this->svc->listForOrg(9);
		$this->assertCount(1, $rows); // revoked binding is hidden
		$this->assertSame('active', $rows[0]['state']);
		$this->assertSame('max', $rows[0]['ncUid']);
	}

	public function testPermsMask(): void
	{
		$this->assertSame(15, SyncBindingService::permsMask('rw'));
		$this->assertSame(1, SyncBindingService::permsMask('ro'));
		// reshare bit (16) is never part of a binding mask (NN-02)
		$this->assertSame(0, SyncBindingService::permsMask('rw') & 16);
		$this->assertSame(0, SyncBindingService::permsMask('ro') & 16);
	}

	// ── CSV classifier (US-S08/MH-07) ──

	public function testClassifyBuckets(): void
	{
		$this->door->method('isRestricted')->willReturn(true);
		$this->userManager->method('get')->willReturnCallback(fn($uid) => match ($uid) {
			'okuid' => $this->user('okuid', 'a@b.de'),
			'staffer' => $this->user('staffer', 's@b.de'),
			'off' => $this->user('off', 'x@b.de', false),
			default => null,
		});
		$this->door->method('isStaff')->willReturnCallback(fn($uid) => $uid === 'staffer');
		$this->identities->method('findAllByNcUid')->willReturn([]);
		$this->assertSame('ok', $this->svc->classifyNcUid('okuid', 'a@b.de'));
		$this->assertSame('uid_unknown', $this->svc->classifyNcUid('ghost', 'a@b.de'));
		$this->assertSame('staff_uid', $this->svc->classifyNcUid('staffer', 'a@b.de'));
		$this->assertSame('uid_disabled', $this->svc->classifyNcUid('off', 'a@b.de'));
		$this->assertSame('uid_mismatch', $this->svc->classifyNcUid('okuid', 'other@b.de'));
		$this->assertSame('ok', $this->svc->classifyNcUid('', 'a@b.de'));
	}

	public function testClassifyDoorOpenBlocksWholeColumn(): void
	{
		$this->door->method('isRestricted')->willReturn(false);
		$this->assertSame('door_open', $this->svc->classifyNcUid('okuid', 'a@b.de'));
	}

	public function testPlanNcUidBucketsAndDuplicate(): void
	{
		$import = new ImportService();
		$orgs = $this->createMock(OrgService::class);
		$ids = $this->createMock(IdentityService::class);
		$rows = ImportService::parse("org_name,email,nc_uid\nA,a@b.de,okuid\nB,b@b.de,ghost\nC,c@b.de,okuid\n");
		$plan = $import->plan($rows, $orgs, $ids, fn(string $u, string $e) => $u === 'okuid' ? 'ok' : 'uid_unknown');
		$this->assertSame('ok', $plan[0]['sync']);
		$this->assertSame('uid_unknown', $plan[1]['sync']);
		$this->assertSame('uid_duplicate', $plan[2]['sync']); // repeated uid → blocked bucket
	}

	// ── upload integrity gate (US-S14) ──

	public function testIntegrityGateSkipsUnboundOrg(): void
	{
		$syncMapper = $this->createMock(SyncBindingMapper::class);
		$syncMapper->method('countActiveRwByOrg')->willReturn(0);
		$uploads = $this->createMock(UploadMapper::class);
		$settings = $this->createMock(\OCA\KanzleiCheck\Service\SettingsService::class);
		$docs = $this->createMock(DocumentService::class);
		$docs->expects($this->never())->method('hashFile'); // no re-hash cost on unbound orgs (AC-4)
		$svc = new \OCA\KanzleiCheck\Service\UploadService($uploads, $settings, $docs, $syncMapper);
		$u = new Upload();
		$u->setId(1);
		$u->setOrgId(9);
		$u->setIntegrity('ok');
		$this->assertSame('ok', $svc->verifyIntegrity($u)->getIntegrity());
	}
}

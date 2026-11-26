<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Identity;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\MemberMapper;
use OCA\KanzleiCheck\Db\Org;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Db\SyncBinding;
use OCA\KanzleiCheck\Db\SyncBindingMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\DocumentService;
use OCA\KanzleiCheck\Service\NotifyService;
use OCA\KanzleiCheck\Service\SyncBindingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Constants;
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
 * Mutation-hunter pass on SyncBindingService: boundary guards, verify
 * state ladder, side-effect assertions (update/audit/notify), throttle,
 * share re-anchor, revoke/repair/setPerms legs, classifier buckets.
 */
class SyncBindingMutationTest extends TestCase
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
	private IURLGenerator&MockObject $urls;
	private NotifyService&MockObject $notify;
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
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->notify = $this->createMock(NotifyService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $this->orgs,
			$this->docs, $this->door, $this->userManager, $this->shares,
			$this->audit, $this->db, $this->urls, $logger, $this->notify,
		);
	}

	private function identity(int $id = 7, ?string $ncUid = 'max', string $status = 'active', string $email = 'mandant@x.de'): Identity
	{
		$i = new Identity();
		$i->setId($id);
		$i->setEmail($email);
		$i->setStatus($status);
		$i->setNcUid($ncUid);
		return $i;
	}

	private function user(string $uid, bool $enabled = true, string $email = 'mandant@x.de'): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn($enabled);
		$u->method('getEMailAddress')->willReturn($email);
		$u->method('getDisplayName')->willReturn('Max M');
		return $u;
	}

	private function binding(int $id = 3, string $state = SyncBinding::ST_ACTIVE): SyncBinding
	{
		$b = new SyncBinding();
		// string numerics on purpose: every (int) cast in the service is
		// load-bearing — a removed cast surfaces as '7' !== 7 in assertions.
		$b->setId($id);
		$b->setIdentityId('7');
		$b->setOrgId('9');
		$b->setShareId('42');
		$b->setShareNodeId('100');
		$b->setPerms(SyncBinding::PERMS_RW);
		$b->setState($state);
		$b->setLastVerifyAt((new \DateTimeImmutable())->modify('-1 hour'));
		return $b;
	}

	private function share(string $id = '42', int $nodeId = 100, int $perms = 15, int $status = IShare::STATUS_ACCEPTED): IShare&MockObject
	{
		$s = $this->createMock(IShare::class);
		$s->method('getId')->willReturn($id);
		$s->method('getNodeId')->willReturn($nodeId);
		$s->method('getPermissions')->willReturn($perms);
		$s->method('getStatus')->willReturn($status);
		return $s;
	}

	private function folder(int $id = 100): Folder&MockObject
	{
		$f = $this->createMock(Folder::class);
		$f->method('getId')->willReturn($id);
		return $f;
	}

	private function orgWithFolder(int $folderId = 100): Org
	{
		$o = new Org();
		$o->setId(9);
		$o->setName('Mandat');
		$o->setState('active');
		$o->setFolderId($folderId);
		return $o;
	}

	private function code(callable $fn): string
	{
		try {
			$fn();
			return 'none';
		} catch (ApiException $e) {
			return $e->getErrorCode();
		} catch (ValidationException $e) {
			return $e->getMessage();
		} catch (NotFoundException) {
			return 'NOT_FOUND';
		}
	}

	/** HTTP status of the thrown ApiException — kills status-code arithmetic mutants. */
	private function httpStatus(callable $fn): int
	{
		try {
			$fn();
			return 0;
		} catch (ApiException $e) {
			return $e->getStatus();
		} catch (ValidationException | NotFoundException) {
			return 0;
		}
	}

	// ── attach boundary guards ──

	public function testAttachUidLengthBoundary(): void
	{
		$i = $this->identity(7, null);
		$this->identities->method('find')->willReturn($i);
		$this->door->method('isRestricted')->willReturn(true);
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$u = $this->user(str_repeat('u', 64));
		$this->userManager->method('get')->willReturn($u);
		$this->identities->method('findAllByNcUid')->willReturn([]);
		// 65 chars must fail before any user lookup (kills GreaterThan flip)
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $this->svc->attach('admin', 7, str_repeat('u', 65))));
		$this->assertSame(400, $this->httpStatus(fn() => $this->svc->attach('admin', 7, str_repeat('u', 65))));
		// whitespace-only trims to empty
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $this->svc->attach('admin', 7, "  \t ")));
		// 64 chars is the max — must pass the guard and bind
		$out = $this->svc->attach('admin', 7, str_repeat('u', 64));
		$this->assertSame(str_repeat('u', 64), $out->getNcUid());
	}

	public function testAttachAlreadyBoundRefused(): void
	{
		$this->identities->method('find')->willReturn($this->identity(7, 'other'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->assertSame('SYNC_ALREADY_BOUND', $this->code(fn() => $this->svc->attach('admin', 7, 'max')));
		$this->assertSame(409, $this->httpStatus(fn() => $this->svc->attach('admin', 7, 'max')));
	}

	public function testAttachSharedAndMismatchFlags(): void
	{
		$i = $this->identity(7, null);
		$this->identities->method('find')->willReturn($i);
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->user('max', true, 'other@x.de'));
		// another identity already bound to 'max'
		$other = $this->identity(8, 'max');
		$this->identities->method('findAllByNcUid')->willReturn([$other]);
		$this->assertSame('SYNC_UID_SHARED', $this->code(fn() => $this->svc->attach('admin', 7, 'max', false, false)));
		$this->assertSame(409, $this->httpStatus(fn() => $this->svc->attach('admin', 7, 'max', false, false)));
		// acceptShared lets it through to the mismatch check
		$this->assertSame('SYNC_EMAIL_MISMATCH', $this->code(fn() => $this->svc->attach('admin', 7, 'max', false, true)));
		$this->assertSame(409, $this->httpStatus(fn() => $this->svc->attach('admin', 7, 'max', false, true)));
		// both accepted → binds, records mismatch=true + shared=true in audit
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'sync.bind', 'identity', 7,
				$this->callback(fn($d) => $d['uid'] === 'max' && $d['mismatch'] === true && $d['shared_uid'] === true));
		$out = $this->svc->attach('admin', 7, 'max', true, true);
		$this->assertTrue($out->getNcUidMismatch());
	}

	public function testAttachSelfRowIsNotShared(): void
	{
		// findAllByNcUid returns THIS identity row (string id '7') — the
		// (int) cast makes it equal to $identityId → not flagged shared.
		$i = $this->identity(7, null);
		$selfRow = $this->identity(7, 'max');
		$selfRow->setId('7'); // string id exercises the (int) cast
		$this->identities->method('find')->willReturn($i);
		$this->identities->method('findAllByNcUid')->willReturn([$selfRow]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		// no acceptShared flag needed — the only "other" row is itself
		$out = $this->svc->attach('admin', 7, 'max');
		$this->assertSame('max', $out->getNcUid());
	}

	public function testAttachInactiveIdentityNotFound(): void
	{
		$this->identities->method('find')->willReturn($this->identity(7, null, 'archived'));
		$this->assertSame('NOT_FOUND', $this->code(fn() => $this->svc->attach('admin', 7, 'max')));
	}

	// ── unbind ──

	public function testUnbindLiveCountBlocks(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->syncs->method('findByIdentity')->willReturn([
			$this->binding(3, SyncBinding::ST_ACTIVE),
			$this->binding(4, SyncBinding::ST_REVOKED),
			$this->binding(5, SyncBinding::ST_BROKEN_SHARE),
		]);
		try {
			$this->svc->unbind('admin', 7);
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('SYNC_BINDINGS_LIVE', $e->getErrorCode());
			$this->assertStringContainsString('2', $e->getMessage()); // revoked row must not count
		}
	}

	public function testUnbindClearsAndAudits(): void
	{
		$i = $this->identity();
		// preset non-null so MethodCallRemoval on the clears is visible
		$i->setNcUidOrigin('attach');
		$i->setNcUidMismatch(true);
		$this->identities->method('find')->willReturn($i);
		$this->syncs->method('findByIdentity')->willReturn([$this->binding(3, SyncBinding::ST_REVOKED)]);
		$this->identities->expects($this->once())->method('update')->with($i);
		$this->audit->expects($this->once())->method('log')->with('admin', 'sync.unbind', 'identity', 7);
		$this->svc->unbind('admin', 7);
		$this->assertNull($i->getNcUid());
		$this->assertNull($i->getNcUidOrigin());
		$this->assertFalse($i->getNcUidMismatch());
	}

	// ── verify ladder ──

	public function testVerifyThrottleSkipsFresh(): void
	{
		$b = $this->binding(3, SyncBinding::ST_ACTIVE);
		$b->setLastVerifyAt(new \DateTimeImmutable()); // fresh — inside 60s window
		$this->identities->expects($this->never())->method('find');
		$this->syncs->expects($this->never())->method('update');
		$out = $this->svc->verify($b);
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
	}

	public function testVerifyForceBypassesThrottle(): void
	{
		$b = $this->binding(3, SyncBinding::ST_ACTIVE);
		$b->setLastVerifyAt(new \DateTimeImmutable());
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		$this->syncs->expects($this->once())->method('update');
		// stale lastVerify → verify() refreshes both timestamps (kill setter-removals)
		$out = $this->svc->verify($b, true);
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
		$this->assertNotNull($out->getUpdatedAt());
		$this->assertGreaterThan((new \DateTimeImmutable())->modify('-10 seconds'), $out->getLastVerifyAt());
	}

	public function testVerifyBrokenUserLegs(): void
	{
		foreach ([null, ''] as $badUid) {
			$b = $this->binding();
			$this->identities = $this->createMock(IdentityMapper::class);
			$this->identities->method('find')->willReturn($this->identity(7, $badUid));
			$svc = new SyncBindingService(
				$this->syncs, $this->identities, $this->members, $this->orgs,
				$this->docs, $this->door, $this->userManager, $this->shares,
				$this->audit, $this->db, $this->urls,
				$this->createMock(LoggerInterface::class), $this->notify,
			);
			$this->assertSame(SyncBinding::ST_BROKEN_USER, $svc->verify($b, true)->getState());
		}
		// uid bound but user gone / disabled
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn(null);
		$this->assertSame(SyncBinding::ST_BROKEN_USER, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyStaffConflict(): void
	{
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->with('max')->willReturn(true);
		$this->assertSame(SyncBinding::ST_STAFF_CONFLICT, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyBrokenShareLegs(): void
	{
		// no org folder node → broken_share
		$b = $this->binding();
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder(0)); // fid<=0 → null node
		$this->assertSame(SyncBinding::ST_BROKEN_SHARE, $this->svc->verify($b, true)->getState());

		// live share absent → broken_share
		$orgs2 = $this->createMock(OrgMapper::class);
		$orgs2->method('find')->willReturn($this->orgWithFolder());
		$docs2 = $this->createMock(DocumentService::class);
		$docs2->method('findNodeById')->willReturn($this->folder());
		$shares2 = $this->createMock(IShareManager::class);
		$shares2->method('getShareById')->willThrowException(new DoesNotExistException('gone'));
		$shares2->method('getSharedWith')->willReturn([]);
		$svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $orgs2,
			$docs2, $this->door, $this->userManager, $shares2,
			$this->audit, $this->db, $this->urls,
			$this->createMock(LoggerInterface::class), $this->notify,
		);
		$this->assertSame(SyncBinding::ST_BROKEN_SHARE, $svc->verify($this->binding(), true)->getState());
	}

	public function testVerifyBrokenPerms(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		// wrong mask
		$this->shares->method('getShareById')->willReturn($this->share('42', 100, Constants::PERMISSION_READ));
		$b = $this->binding(3, SyncBinding::ST_ACTIVE); // perms=rw → want 15
		$this->assertSame(SyncBinding::ST_BROKEN_PERMS, $this->svc->verify($b, true)->getState());
	}

	public function testVerifyShareBitSetIsBrokenPerms(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share('42', 100, 15 | Constants::PERMISSION_SHARE));
		$this->assertSame(SyncBinding::ST_BROKEN_PERMS, $this->svc->verify($this->binding(), true)->getState());
	}

	public function testVerifyPendingStatus(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share('42', 100, 15, IShare::STATUS_PENDING));
		$this->assertSame(SyncBinding::ST_PENDING, $this->svc->verify($this->binding(), true)->getState());
	}

	public function testVerifyReanchorsToLiveShareId(): void
	{
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$node = $this->folder();
		$this->docs->method('findNodeById')->willReturn($node);
		// stored share id stale, folder-scoped lookup finds the live share
		$this->shares->method('getShareById')->willThrowException(new DoesNotExistException('gone'));
		$this->shares->method('getSharedWith')->with('max', IShare::TYPE_USER, $node, 10, 0)
			->willReturn([$this->share('77', 100)]);
		$b = $this->binding();
		$out = $this->svc->verify($b, true);
		$this->assertSame('77', $out->getShareId());
		$this->assertSame(100, $out->getShareNodeId());
	}

	public function testVerifyBreakAuditsAndNotifies(): void
	{
		$b = $this->binding(3, SyncBinding::ST_ACTIVE);
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn(null); // → broken_user
		$this->audit->expects($this->once())->method('log')
			->with('system:sync', 'sync.verify_break', 'sync', 3,
				$this->callback(fn($d) => $d['from'] === 'active' && $d['to'] === 'broken_user'
					&& $d['identity'] === 7 && $d['org'] === 9 && $d['uid'] === 'max'));
		$this->urls->method('linkToRouteAbsolute')->willReturn('https://nc/apps/kanzleicheck/');
		$this->notify->method('staffRecipients')->with(9)->willReturn(['boss']);
		$this->notify->expects($this->once())->method('staffNotify')
			->with('boss', 'portal_sync_break', 9, 'https://nc/apps/kanzleicheck/?view=orgs');
		$this->svc->verify($b, true);
	}

	public function testVerifyNoAuditWhenHealthyTransition(): void
	{
		// pending → active is healthy→healthy — no break audit
		$b = $this->binding(3, SyncBinding::ST_PENDING);
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		$this->audit->expects($this->never())->method('log');
		$this->notify->expects($this->never())->method('staffNotify');
		$this->assertSame(SyncBinding::ST_ACTIVE, $this->svc->verify($b, true)->getState());
	}

	// ── revoke ──

	public function testRevokeDropsShareAndAudits(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity());
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$share = $this->share();
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('deleteShare')->with($share);
		$this->syncs->expects($this->once())->method('update')->with($b);
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'sync.revoke', 'sync', 3,
				$this->callback(fn($d) => $d['identity'] === 7 && $d['org'] === 9));
		$out = $this->svc->revoke('admin', 3);
		$this->assertSame(SyncBinding::ST_REVOKED, $out->getState());
		$this->assertNotNull($out->getUpdatedAt()); // setter-removal sentinel
	}

	public function testRevokeNoUidSkipsShareLookup(): void
	{
		// identity lost its uid → dropShare must not even look for a share
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, null));
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->expects($this->never())->method('getShareById');
		$this->shares->expects($this->never())->method('getSharedWith');
		$this->assertSame(SyncBinding::ST_REVOKED, $this->svc->revoke('admin', 3)->getState());
	}

	public function testRevokeTerminalIsNoop(): void
	{
		$b = $this->binding(3, SyncBinding::ST_REVOKED);
		$this->syncs->method('find')->willReturn($b);
		$this->syncs->expects($this->never())->method('update');
		$this->shares->expects($this->never())->method('deleteShare');
		$this->assertSame(SyncBinding::ST_REVOKED, $this->svc->revoke('admin', 3)->getState());
	}

	// ── repair ──

	public function testRepairBrokenUserNeedsUserAdmin(): void
	{
		$this->syncs->method('find')->willReturn($this->binding(3, SyncBinding::ST_BROKEN_USER));
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $this->svc->repair('admin', 3)));
	}

	public function testRepairHealthyIsNoop(): void
	{
		$this->syncs->method('find')->willReturn($this->binding(3, SyncBinding::ST_ACTIVE));
		$this->syncs->expects($this->never())->method('update');
		$this->assertSame(SyncBinding::ST_ACTIVE, $this->svc->repair('admin', 3)->getState());
	}

	public function testRepairEmptyUidAndDisabledUserLegs(): void
	{
		$b = $this->binding(3, SyncBinding::ST_BROKEN_SHARE);
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, '')); // empty uid leg
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $this->svc->repair('admin', 3)));

		$this->identities = $this->createMock(IdentityMapper::class);
		$this->identities->method('find')->willReturn($this->identity(7, 'max'));
		$svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $this->orgs,
			$this->docs, $this->door, $this->userManager, $this->shares,
			$this->audit, $this->db, $this->urls,
			$this->createMock(LoggerInterface::class), $this->notify,
		);
		$this->userManager->method('get')->willReturn($this->user('max', false)); // disabled
		$this->assertSame('SYNC_UID_UNKNOWN', $this->code(fn() => $svc->repair('admin', 3)));
	}

	public function testRepairNormalisesPermsInPlace(): void
	{
		$b = $this->binding(3, SyncBinding::ST_BROKEN_PERMS);
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$share = $this->share('42', 100, Constants::PERMISSION_READ); // wrong mask
		$share->expects($this->once())->method('setPermissions')->with(15);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('updateShare')->willReturn($share);
		$this->audit->expects($this->once())->method('log')->with('admin', 'sync.repair', 'sync', 3);
		$out = $this->svc->repair('admin', 3);
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
		$this->assertSame('42', $out->getShareId());
		$this->assertSame(100, $out->getShareNodeId()); // (int) cast on node id
		$this->assertSame('admin', $out->getSharedBy());
		$this->assertNotNull($out->getLastVerifyAt());
		$this->assertNotNull($out->getUpdatedAt());
	}

	public function testSetPermsEmptyValueRejected(): void
	{
		$this->assertSame('VALIDATION_FAILED', $this->code(fn() => $this->svc->setPerms('admin', 3, '')));
	}

	public function testSetPermsWithoutUidSkipsShareButPersists(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity(7, null)); // no uid
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->expects($this->never())->method('updateShare');
		$this->syncs->expects($this->once())->method('update')->with($b);
		$out = $this->svc->setPerms('admin', 3, SyncBinding::PERMS_RO);
		$this->assertSame('ro', $out->getPerms());
		$this->assertNotNull($out->getUpdatedAt());
	}

	// ── setPerms ──

	public function testSetPermsValidationAndUpdate(): void
	{
		$this->assertSame('VALIDATION_FAILED', $this->code(fn() => $this->svc->setPerms('admin', 3, 'bogus')));
		$this->syncs->method('find')->willReturn($this->binding(3, SyncBinding::ST_REVOKED));
		try {
			$this->svc->setPerms('admin', 3, SyncBinding::PERMS_RO);
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame('binding_revoked', $e->getFields()['perms']);
		}
	}

	public function testSetPermsUpdatesLiveShare(): void
	{
		$b = $this->binding();
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity());
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$share = $this->share();
		$share->expects($this->once())->method('setPermissions')->with(Constants::PERMISSION_READ);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('updateShare')->with($share);
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'sync.perms', 'sync', 3, ['perms' => 'ro']);
		$out = $this->svc->setPerms('admin', 3, SyncBinding::PERMS_RO);
		$this->assertSame(SyncBinding::PERMS_RO, $out->getPerms());
	}

	// ── classifyNcUid buckets ──

	public function testClassifyNcUidBuckets(): void
	{
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturnMap([['staffer', true], ['max', false], ['dis', false], ['shared', false]]);
		$this->userManager->method('get')->willReturnMap([
			['max', $this->user('max')],
			['staffer', $this->user('staffer')],
			['dis', $this->user('dis', false)],
			['shared', $this->user('shared', true, 'a@b.c')],
		]);
		$this->identities->method('findAllByNcUid')->willReturnMap([
			['max', []],
			['shared', [$this->identity(9, 'shared', 'active', 'other@x.de')]],
		]);
		$this->assertSame('ok', $this->svc->classifyNcUid('', 'a@b.c'));            // empty column
		$this->assertSame('ok', $this->svc->classifyNcUid('max', 'mandant@x.de')); // healthy + email match
		$this->assertSame('uid_mismatch', $this->svc->classifyNcUid('max', 'a@b.c'));
		$this->assertSame('uid_unknown', $this->svc->classifyNcUid('ghost', 'a@b.c'));
		$this->assertSame('staff_uid', $this->svc->classifyNcUid('staffer', 'a@b.c'));
		$this->assertSame('uid_disabled', $this->svc->classifyNcUid('dis', 'a@b.c'));
		$this->assertSame('uid_shared', $this->svc->classifyNcUid('shared', 'a@b.c'));
	}

	public function testClassifyDoorOpenShortCircuits(): void
	{
		$this->door->method('isRestricted')->willReturn(false);
		$this->assertSame('door_open', $this->svc->classifyNcUid('max', 'a@b.c'));
	}

	// ── enable guards ──

	public function testEnableGuardMatrix(): void
	{
		$org = $this->orgWithFolder();
		$this->orgs->method('find')->willReturn($org);
		$this->identities->method('find')->willReturn($this->identity());
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		// org offboarded beats every later check (ZF-03 order)
		$org->setState('offboarded');
		$this->assertSame('SYNC_ORG_OFFBOARDED', $this->code(fn() => $this->svc->enable('admin', 9, 7)));
		$this->assertSame(409, $this->httpStatus(fn() => $this->svc->enable('admin', 9, 7)));
	}

	public function testEnableNotBoundAndDoorOpen(): void
	{
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->identities->method('find')->willReturn($this->identity(7, null));
		$this->assertSame('SYNC_NOT_BOUND', $this->code(fn() => $this->svc->enable('admin', 9, 7)));
		$this->assertSame(400, $this->httpStatus(fn() => $this->svc->enable('admin', 9, 7)));
	}

	public function testEnableDocsRootUnhealthy(): void
	{
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(false);
		$this->assertSame('NO_DOCS_ROOT', $this->code(fn() => $this->svc->enable('admin', 9, 7)));
		$this->assertSame(503, $this->httpStatus(fn() => $this->svc->enable('admin', 9, 7)));
	}

	public function testEnableShareFailureWrapsInNoSharePerm(): void
	{
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(true);
		$this->docs->method('orgFolder')->willReturn($this->folder());
		$this->shares->method('newShare')->willReturn($this->share());
		$this->shares->method('createShare')->willThrowException(new \RuntimeException('denied'));
		try {
			$this->svc->enable('admin', 9, 7);
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame('NO_SHARE_PERM', $e->getErrorCode());
			$this->assertSame(403, $e->getStatus());
			$this->assertStringContainsString('denied', $e->getMessage()); // error detail concat
		}
	}

	public function testEnableHappyPathFields(): void
	{
		$org = $this->orgWithFolder(100); // org folder rebind leg: stored id ≠ live id
		$this->orgs->method('find')->willReturn($org);
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(true);
		$node = $this->folder(55);
		$this->docs->method('orgFolder')->willReturn($node);
		$newShare = $this->share('99', 55, 15, IShare::STATUS_PENDING);
		$newShare->expects($this->once())->method('setNode')->with($node);
		$newShare->expects($this->once())->method('setShareType')->with(IShare::TYPE_USER);
		$newShare->expects($this->once())->method('setSharedWith')->with('max');
		$newShare->expects($this->once())->method('setSharedBy')->with('admin');
		$newShare->expects($this->once())->method('setPermissions')->with(15); // reshare bit never set
		$this->shares->method('newShare')->willReturn($newShare);
		$this->shares->method('createShare')->willReturn($newShare);
		$this->orgs->expects($this->once())->method('update')->with($org); // folder rebind
		$this->syncs->expects($this->once())->method('insert')
			->willReturnCallback(fn(SyncBinding $b) => $b);
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'sync.enable', 'sync', $this->anything(),
				$this->callback(fn($d) => $d['uid'] === 'max' && $d['identity'] === 7 && $d['org'] === 9 && $d['state'] === 'pending_accept'));
		$out = $this->svc->enable('admin', 9, 7);
		$this->assertSame(SyncBinding::ST_PENDING, $out->getState()); // pending share → pending_accept
		$this->assertSame('99', $out->getShareId());
		$this->assertSame(55, $out->getShareNodeId());
		$this->assertSame('admin', $out->getSharedBy());
		$this->assertSame('admin', $out->getCreatedBy());
		$this->assertNotNull($out->getCreatedAt());
		$this->assertSame(SyncBinding::PERMS_RW, $out->getPerms());
		$this->assertSame(7, $out->getIdentityId());
		$this->assertSame(9, $out->getOrgId());
		$this->assertNull($out->getLastVerifyAt()); // creation is not verification
		$this->assertNotNull($out->getUpdatedAt());
		$this->assertSame(55, $org->getFolderId()); // rebound to live folder
	}

	public function testEnableRevivesTerminalRowInPlace(): void
	{
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$revoked = $this->binding(3, SyncBinding::ST_REVOKED);
		$this->syncs->method('findByIdentityAndOrg')->willReturn($revoked);
		$this->docs->method('rootHealthy')->willReturn(true);
		$this->docs->method('orgFolder')->willReturn($this->folder());
		$share = $this->share('50');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturn($share);
		// unique key occupied → update in place, never insert
		$this->syncs->expects($this->never())->method('insert');
		$this->syncs->expects($this->once())->method('update')->with($revoked)
			->willReturnCallback(fn(SyncBinding $b) => $b);
		$out = $this->svc->enable('admin', 9, 7);
		$this->assertSame(3, $out->getId());
		$this->assertSame(SyncBinding::ST_ACTIVE, $out->getState());
	}

	// ── listForOrg ──

	public function testListForOrgSkipsTerminalAndMapsFields(): void
	{
		$live = $this->binding(3, SyncBinding::ST_ACTIVE);
		$this->syncs->method('findByOrg')->willReturn([
			$live,
			$this->binding(4, SyncBinding::ST_REVOKED),
		]);
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		$rows = $this->svc->listForOrg(9);
		$this->assertCount(1, $rows);
		$this->assertSame(3, $rows[0]['bindingId']);
		$this->assertSame(7, $rows[0]['identityId']); // (int) cast on string-stored field
		$this->assertSame('max', $rows[0]['ncUid']);
		$this->assertSame('Max M', $rows[0]['ncDisplay']);
		$this->assertFalse($rows[0]['ncUidMismatch']);
		$this->assertSame('rw', $rows[0]['perms']);
		$this->assertSame('active', $rows[0]['state']);
		$this->assertNotNull($rows[0]['verifiedAt']);
	}

	// ── suggestFor ──

	public function testSuggestFor(): void
	{
		$emptyMail = $this->identity(7, null, 'active', '');
		$this->assertNull($this->svc->suggestFor($emptyMail)); // no email → no suggestion

		$i = $this->identity(7, null, 'active', 'mandant@x.de');
		$match = $this->user('max', true, 'Mandant@x.de'); // case-insensitive normalised match
		$staff = $this->user('boss', true, 'mandant@x.de');
		$disabled = $this->user('dis', false, 'mandant@x.de');
		$this->userManager->method('search')->with('mandant@x.de', 10)->willReturn([$disabled, $staff, $match]);
		$this->door->method('isStaff')->willReturnMap([['boss', true], ['max', false]]);
		$out = $this->svc->suggestFor($i);
		$this->assertSame('max', $out['uid']); // disabled + staff skipped, enabled non-staff wins
		$this->assertSame('Max M', $out['displayName']);
		$this->assertSame('Mandant@x.de', $out['email']); // raw address, not normalised

		// no candidate matching → null
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('search')->willReturn([$this->user('max', true, 'other@x.de')]);
		$svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $this->orgs,
			$this->docs, $this->door, $this->userManager, $this->shares,
			$this->audit, $this->db, $this->urls,
			$this->createMock(LoggerInterface::class), $this->notify,
		);
		$this->assertNull($svc->suggestFor($i));
	}

	// ── cascades ──

	public function testCascadeOffboardCountsAndToleratesFailures(): void
	{
		$ok = $this->binding(3, SyncBinding::ST_ACTIVE);
		$dead = $this->binding(4, SyncBinding::ST_REVOKED); // terminal → skipped
		$bad = $this->binding(5, SyncBinding::ST_ACTIVE);
		$this->syncs->method('findByOrg')->willReturn([$ok, $dead, $bad]);
		$this->syncs->method('find')->willReturnCallback(
			fn(int $id) => $id === 3 ? $ok : $bad
		);
		$this->identities->method('find')->willReturn($this->identity());
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		// revoke(5) dies at the row update → failed++, cascade continues
		$this->syncs->method('update')->willReturnCallback(
			fn(SyncBinding $b) => $b->getId() === 5 ? throw new \RuntimeException('db gone') : $b
		);
		$out = $this->svc->cascadeOffboard('admin', 9);
		$this->assertSame(1, $out['processed']);
		$this->assertSame(1, $out['failed']);
	}

	public function testCascadeOffboardSuccess(): void
	{
		$ok = $this->binding(3, SyncBinding::ST_ACTIVE);
		$this->syncs->method('findByOrg')->willReturn([$ok]);
		$this->syncs->method('find')->willReturn($ok);
		$this->identities->method('find')->willReturn($this->identity());
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		$out = $this->svc->cascadeOffboard('admin', 9);
		$this->assertSame(1, $out['processed']);
		$this->assertSame(0, $out['failed']);
	}

	public function testCascadeEraseMissingIdentity(): void
	{
		// identity row already gone → rows still deleted, no unbind audit
		$this->identities->method('find')->willReturn(null);
		$this->syncs->method('findByIdentity')->willReturn([]);
		$this->syncs->expects($this->once())->method('deleteByIdentity')->with(7);
		$this->identities->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('log');
		$this->svc->cascadeErase('admin', 7);
	}

	public function testCascadeEraseRevokeFailureDoesNotBlockDelete(): void
	{
		$i = $this->identity();
		// preset so the erase clears are observable (setter-removal sentinels)
		$i->setNcUidOrigin('attach');
		$i->setNcUidMismatch(true);
		$this->identities->method('find')->willReturn($i);
		$this->syncs->method('findByIdentity')->willReturn([$this->binding(3, SyncBinding::ST_ACTIVE)]);
		$this->syncs->method('find')->willThrowException(new \RuntimeException('db gone'));
		// rows are still deleted and the uid cleared even when revoke throws (AS-05)
		$this->syncs->expects($this->once())->method('deleteByIdentity')->with(7);
		$this->identities->expects($this->once())->method('update')->with($i);
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'sync.unbind', 'identity', 7, ['cascade' => 'erase']);
		$this->svc->cascadeErase('admin', 7);
		$this->assertNull($i->getNcUid());
		$this->assertNull($i->getNcUidOrigin());
		$this->assertFalse($i->getNcUidMismatch());
	}

	public function testCascadeMemberRemove(): void
	{
		// no binding → nothing revoked
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->syncs->expects($this->never())->method('update');
		$this->svc->cascadeMemberRemove('admin', 7, 9);

		// live binding → revoked through the revoke path
		$b = $this->binding();
		$this->syncs = $this->createMock(SyncBindingMapper::class);
		$this->syncs->method('findByIdentityAndOrg')->willReturn($b);
		$this->syncs->method('find')->willReturn($b);
		$this->identities->method('find')->willReturn($this->identity());
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		$svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $this->orgs,
			$this->docs, $this->door, $this->userManager, $this->shares,
			$this->audit, $this->db, $this->urls,
			$this->createMock(LoggerInterface::class), $this->notify,
		);
		$svc->cascadeMemberRemove('admin', 7, 9);
		$this->assertSame(SyncBinding::ST_REVOKED, $b->getState());
	}

	// ── reconcile + diagnostics ──

	public function testReconcileCountsAndCursor(): void
	{
		$live = $this->binding(10, SyncBinding::ST_ACTIVE);
		$term = $this->binding(11, SyncBinding::ST_REVOKED);
		$this->syncs->method('findPage')->with(5, 2)->willReturn([$live, $term]);
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn(null); // → broken_user
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('kanzleicheck sync reconcile run',
				$this->callback(fn($c) => $c['processed'] === 1 && $c['broken'] === 1));
		$svc = new SyncBindingService(
			$this->syncs, $this->identities, $this->members, $this->orgs,
			$this->docs, $this->door, $this->userManager, $this->shares,
			$this->audit, $this->db, $this->urls, $logger, $this->notify,
		);
		$out = $svc->reconcile(5, 2);
		$this->assertSame(11, $out['lastId']);       // terminal row still advances the cursor
		$this->assertSame(1, $out['processed']);     // terminal row not processed
		$this->assertSame(['ok' => 0, 'broken' => 1, 'conflict' => 0], $out['counts']);
	}

	public function testReconcileHealthyBucket(): void
	{
		$live = $this->binding(10, SyncBinding::ST_ACTIVE);
		$this->syncs->method('findPage')->willReturn([$live]);
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isStaff')->willReturn(false);
		$this->orgs->method('find')->willReturn($this->orgWithFolder());
		$this->docs->method('findNodeById')->willReturn($this->folder());
		$this->shares->method('getShareById')->willReturn($this->share());
		$out = $this->svc->reconcile(0, 10);
		$this->assertSame(['ok' => 1, 'broken' => 0, 'conflict' => 0], $out['counts']);
		$this->assertSame(1, $out['processed']);
	}

	public function testDiagnosticsSnapshot(): void
	{
		$this->syncs->method('countNonTerminal')->willReturn(3);
		$this->syncs->method('findNonTerminal')->willReturn([
			$this->binding(1), $this->binding(2),
		]);
		$this->identities->method('find')->willReturnMap([
			[7, $this->identity(7, 'max')],
		]);
		$this->door->method('isStaff')->with('max')->willReturn(true);
		$this->door->method('isRestricted')->willReturn(true);
		$this->syncs->method('countByState')->willReturnMap([
			[SyncBinding::ST_BROKEN_USER, 1],
			[SyncBinding::ST_BROKEN_SHARE, 2],
			[SyncBinding::ST_BROKEN_PERMS, 3],
		]);
		$out = $this->svc->diagnosticsSnapshot();
		$this->assertSame(3, $out['bindings']);
		$this->assertFalse($out['doorOpen']);
		$this->assertSame(6, $out['broken']); // sum of all three broken buckets
		$this->assertSame(2, $out['staffBound']); // both rows resolve uid 'max' (staff)
	}

	public function testDiagnosticsSkipsMissingIdentity(): void
	{
		$this->syncs->method('countNonTerminal')->willReturn(1);
		$this->syncs->method('findNonTerminal')->willReturn([$this->binding(1)]);
		$this->identities->method('find')->willReturn(null); // identity gone → not staff-bound
		$this->door->method('isRestricted')->willReturn(false); // door open
		$this->syncs->method('countByState')->willReturn(0);
		$out = $this->svc->diagnosticsSnapshot();
		$this->assertSame(0, $out['staffBound']);
		$this->assertTrue($out['doorOpen']);
	}

	public function testEnableNoRebindWhenFolderIdMatchesAsString(): void
	{
		// stored folder id as STRING '55' — the (int) casts make it equal to
		// the live folder id → no org update (kill CastInt removal)
		$org = $this->orgWithFolder(55);
		$org->setFolderId('55');
		$this->orgs->method('find')->willReturn($org);
		$this->members->method('findByIdentityAndOrg')->willReturn(new \OCA\KanzleiCheck\Db\Member());
		$this->identities->method('find')->willReturn($this->identity());
		$this->userManager->method('get')->willReturn($this->user('max'));
		$this->door->method('isRestricted')->willReturn(true);
		$this->door->method('isStaff')->willReturn(false);
		$this->syncs->method('findByIdentityAndOrg')->willReturn(null);
		$this->docs->method('rootHealthy')->willReturn(true);
		$this->docs->method('orgFolder')->willReturn($this->folder(55));
		$share = $this->share('60', 55);
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturn($share);
		$this->orgs->expects($this->never())->method('update'); // no rebind needed
		$this->syncs->method('insert')->willReturnCallback(fn(SyncBinding $b) => $b);
		$this->svc->enable('admin', 9, 7);
	}
}

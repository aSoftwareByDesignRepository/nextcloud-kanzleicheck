<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Identity;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\Member;
use OCA\KanzleiCheck\Db\MemberMapper;
use OCA\KanzleiCheck\Db\Org;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\LicenseService;
use OCA\KanzleiCheck\Service\OrgService;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * IdentityService + OrgService — the tenant boundary. Email normalisation,
 * upsert/add idem races, membership cap + role whitelist, active-status
 * gates (auth cut-off), org CRUD validation, ref uniqueness, booking-url
 * SSRF surface, licence-gated app enablement, offboard.
 */
class OrgIdentityServiceTest extends TestCase
{
	private function uniq(): DbException
	{
		return new class extends DbException {
			public function getReason(): ?int
			{
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	/** IDBConnection mock whose QB chain returns a lockable row (FOR UPDATE). */
	private function lockDb(): IDBConnection&MockObject
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=1');
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(1);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$qb->method($m)->willReturn($qb);
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	// ── IdentityService ──

	public function testEmailNormaliseAndValidate(): void
	{
		$this->assertSame('a@x.de', IdentityService::normalizeEmail(' A@X.DE '));
		$this->assertTrue(IdentityService::isValidEmail('a@x.de'));
		// near the 254-char RFC ceiling: 64-char local + legal ≤63-char labels
		$dom = str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.de'; // 131
		$this->assertTrue(IdentityService::isValidEmail(str_repeat('a', 64) . '@' . $dom));
		// exactly 254 → valid, 255 → invalid (the <= is load-bearing)
		$dom254 = str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 58) . '.de'; // 188
		$this->assertSame(254, strlen(str_repeat('a', 64) . '@' . $dom254));
		$this->assertTrue(IdentityService::isValidEmail(str_repeat('a', 64) . '@' . $dom254));
		$this->assertFalse(IdentityService::isValidEmail(str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 60) . '.de'));
		$this->assertFalse(IdentityService::isValidEmail(str_repeat('a', 65) . '@x.de')); // local part >64
		$this->assertFalse(IdentityService::isValidEmail('not-an-email'));
		$this->assertFalse(IdentityService::isValidEmail(''));
	}

	public function testUpsertInvalidEmailRejected(): void
	{
		$svc = new IdentityService($this->createMock(IdentityMapper::class), $this->createMock(MemberMapper::class), $this->lockDb());
		try {
			$svc->upsert('bogus', 'X');
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['email' => 'invalid'], $e->getFields());
		}
	}

	public function testUpsertExistingReturnsWithoutInsert(): void
	{
		$have = new Identity();
		$mapper = $this->createMock(IdentityMapper::class);
		$mapper->method('findByEmail')->with('a@x.de')->willReturn($have);
		$mapper->expects($this->never())->method('insert');
		$svc = new IdentityService($mapper, $this->createMock(MemberMapper::class), $this->lockDb());
		$this->assertSame($have, $svc->upsert(' A@X.DE ', 'Name')); // normalised before lookup
	}

	public function testUpsertNewIdentityFields(): void
	{
		$mapper = $this->createMock(IdentityMapper::class);
		$mapper->method('findByEmail')->willReturn(null);
		$captured = null;
		$mapper->method('insert')->willReturnCallback(function (Identity $i) use (&$captured) {
			$captured = $i;
			return $i;
		});
		$svc = new IdentityService($mapper, $this->createMock(MemberMapper::class), $this->lockDb());
		$svc->upsert(' A@X.DE ', '  ');
		$this->assertSame('a@x.de', $captured->getEmail());
		$this->assertSame('a@x.de', $captured->getDisplayName()); // blank name → email fallback
		$this->assertSame('active', $captured->getStatus());
		$this->assertNotNull($captured->getCreatedAt());

		// long display name truncated to 190 chars (mb, not bytes)
		$svc->upsert('b@x.de', str_repeat('ü', 300));
		$this->assertSame(190, mb_strlen($captured->getDisplayName()));
	}

	public function testUpsertRaceReturnsWinner(): void
	{
		$winner = new Identity();
		$mapper = $this->createMock(IdentityMapper::class);
		$mapper->method('findByEmail')->willReturnOnConsecutiveCalls(null, $winner);
		$mapper->method('insert')->willThrowException($this->uniq());
		$svc = new IdentityService($mapper, $this->createMock(MemberMapper::class), $this->lockDb());
		$this->assertSame($winner, $svc->upsert('a@x.de', 'N'));

		// unique violation but winner never appears → rethrow, not null
		$mapper2 = $this->createMock(IdentityMapper::class);
		$mapper2->method('findByEmail')->willReturn(null);
		$mapper2->method('insert')->willThrowException($this->uniq());
		$svc2 = new IdentityService($mapper2, $this->createMock(MemberMapper::class), $this->lockDb());
		$this->expectException(DbException::class);
		$svc2->upsert('a@x.de', 'N');
	}

	public function testAddMembershipCapAndIdempotency(): void
	{
		$members = $this->createMock(MemberMapper::class);
		$members->method('countByIdentity')->willReturn(IdentityService::MAX_MEMBERSHIPS);
		$svc = new IdentityService($this->createMock(IdentityMapper::class), $members, $this->lockDb());
		try {
			$svc->addMembership(1, 2, 'staff');
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['org' => 'membership_limit'], $e->getFields());
		}

		// existing row → idempotent return, no insert
		$have = new Member();
		$members2 = $this->createMock(MemberMapper::class);
		$members2->method('countByIdentity')->willReturn(0);
		$members2->method('findByIdentityAndOrg')->willReturn($have);
		$members2->expects($this->never())->method('insert');
		$svc2 = new IdentityService($this->createMock(IdentityMapper::class), $members2, $this->lockDb());
		$this->assertSame($have, $svc2->addMembership(1, 2, 'staff'));
	}

	public function testAddMembershipRoleWhitelistAndRace(): void
	{
		foreach (['member' => 'member', 'readonly' => 'readonly', 'admin' => 'member'] as $in => $want) {
			$members = $this->createMock(MemberMapper::class);
			$members->method('countByIdentity')->willReturn(0);
			$members->method('findByIdentityAndOrg')->willReturn(null);
			$captured = null;
			$members->method('insert')->willReturnCallback(function (Member $m) use (&$captured) {
				$captured = $m;
				return $m;
			});
			$svc = new IdentityService($this->createMock(IdentityMapper::class), $members, $this->lockDb());
			$svc->addMembership(1, 2, 'creator', $in);
			$this->assertSame($want, $captured->getRole(), "role $in");
			$this->assertSame(1, $captured->getIdentityId());
			$this->assertSame(2, $captured->getOrgId());
			$this->assertSame('creator', $captured->getCreatedBy());
			$this->assertNotNull($captured->getCreatedAt());
		}
		// race: insert hits unique → winner row returned
		$winner = new Member();
		$members2 = $this->createMock(MemberMapper::class);
		$members2->method('countByIdentity')->willReturn(0);
		$members2->method('findByIdentityAndOrg')->willReturnOnConsecutiveCalls(null, $winner);
		$members2->method('insert')->willThrowException($this->uniq());
		$svc2 = new IdentityService($this->createMock(IdentityMapper::class), $members2, $this->lockDb());
		$this->assertSame($winner, $svc2->addMembership(1, 2, 'creator'));
	}

	public function testActiveGatesCutOffDeadIdentities(): void
	{
		$dead = new Identity();
		$dead->setStatus('disabled');
		$live = new Identity();
		$live->setStatus('active');
		$mapper = $this->createMock(IdentityMapper::class);
		$mapper->method('findByEmail')->willReturnMap([
			['live@x.de', $live], ['dead@x.de', $dead], ['gone@x.de', null],
		]);
		$mapper->method('find')->willReturnMap([[1, $live], [2, $dead], [3, null]]);
		$svc = new IdentityService($mapper, $this->createMock(MemberMapper::class), $this->lockDb());
		$this->assertSame($live, $svc->activeByEmail(' LIVE@X.DE '));
		$this->assertNull($svc->activeByEmail('dead@x.de')); // disabled ≠ auth
		$this->assertNull($svc->activeByEmail('gone@x.de'));
		$this->assertSame($live, $svc->activeById(1));
		$this->assertNull($svc->activeById(2));
		$this->assertNull($svc->activeById(3));
	}

	public function testOrgIdsForAndIsMember(): void
	{
		$m1 = new Member();
		$m1->setOrgId(5);
		$m2 = new Member();
		$m2->setOrgId('9'); // stringly-typed from DB — (int) cast load-bearing
		$members = $this->createMock(MemberMapper::class);
		$members->method('findByIdentity')->with(1)->willReturn([$m1, $m2]);
		$members->method('findByIdentityAndOrg')->willReturnMap([
			[1, 5, $m1], [1, 99, null],
		]);
		$svc = new IdentityService($this->createMock(IdentityMapper::class), $members, $this->lockDb());
		$this->assertSame([5, 9], $svc->orgIdsFor(1));
		$this->assertTrue($svc->isMember(1, 5));
		$this->assertFalse($svc->isMember(1, 99));
	}

	// ── OrgService ──

	private function orgSvc(OrgMapper&MockObject $orgs, ?MockObject $lic = null, ?MockObject $audit = null): OrgService
	{
		return new OrgService($orgs,
			$audit ?? $this->createMock(AuditService::class),
			$lic ?? $this->createMock(LicenseService::class));
	}

	public function testFindByRefPublicDelegates(): void
	{
		$org = new Org();
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->expects($this->once())->method('findByRef')->with('r1')->willReturn($org);
		$this->assertSame($org, $this->orgSvc($orgs)->findByRefPublic('r1'));
	}

	public function testMustGet404(): void
	{
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturnMap([[1, new Org()], [9, null]]);
		$svc = $this->orgSvc($orgs);
		$this->assertInstanceOf(Org::class, $svc->mustGet(1));
		$this->expectException(NotFoundException::class);
		$svc->mustGet(9);
	}

	public function testCreateValidation(): void
	{
		$orgs = $this->createMock(OrgMapper::class);
		$svc = $this->orgSvc($orgs);
		foreach (['', '   ', str_repeat('n', 191)] as $bad) {
			try {
				$svc->create('staff', $bad, null);
				$this->fail("expected for name len " . strlen($bad));
			} catch (ValidationException $e) {
				$this->assertSame(['name' => 'required'], $e->getFields());
			}
		}
		// boundary: exactly 190 chars is a legal name
		$orgs->method('insert')->willReturnArgument(0);
		$ok = $svc->create('staff', str_repeat('n', 190), null);
		$this->assertSame(190, mb_strlen($ok->getName()));
		// ref taken → field error
		$orgs2 = $this->createMock(OrgMapper::class);
		$orgs2->method('findByRef')->with('r1')->willReturn(new Org());
		try {
			$this->orgSvc($orgs2)->create('staff', 'N', 'r1');
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['ref' => 'taken'], $e->getFields());
		}
	}

	public function testCreateFieldNormalisationAndAudit(): void
	{
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('findByRef')->willReturn(null);
		$captured = null;
		$orgs->method('insert')->willReturnCallback(function (Org $o) use (&$captured) {
			$o->setId(7);
			$captured = $o;
			return $o;
		});
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->exactly(2))->method('log')->with('staff', 'org.create', 'org', 7);
		$svc = $this->orgSvc($orgs, null, $audit);
		$svc->create('staff', '  Firm GmbH  ', ' ' . str_repeat('r', 70) . ' ', 'bogus-kind');
		$this->assertSame('Firm GmbH', $captured->getName());
		$this->assertSame(64, mb_strlen($captured->getRef())); // truncated
		$this->assertSame('company', $captured->getKind());    // invalid → default
		$this->assertSame('active', $captured->getState());
		$this->assertFalse($captured->getAppOn());             // never auto-enabled
		$this->assertSame('staff', $captured->getCreatedBy());
		$this->assertNotNull($captured->getCreatedAt());
		$this->assertNotNull($captured->getUpdatedAt());
		// valid kinds pass through, empty ref → null
		$svc->create('staff', 'B', '', 'object');
		$this->assertNull($captured->getRef());
		$this->assertSame('object', $captured->getKind());
	}

	public function testCreateRefRaceMapsToFieldError(): void
	{
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('findByRef')->willReturn(null); // TOCTOU: lookup passes
		$orgs->method('insert')->willThrowException($this->uniq()); // race loses at DB
		$svc = $this->orgSvc($orgs);
		try {
			$svc->create('staff', 'N', 'r1');
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['ref' => 'taken'], $e->getFields());
		}
	}

	public function testUpdateFieldMatrix(): void
	{
		$org = new Org();
		$org->setId(3);
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturn($org);
		$orgs->method('findByRef')->willReturn(null);
		$orgs->expects($this->once())->method('update')->with($org);
		$svc = $this->orgSvc($orgs);
		$out = $svc->update('staff', 3, [
			'name' => ' New Name ',
			'ref' => 'r2',
			'kind' => 'private',
			'pcCustId' => '42',      // string → int cast
			'bookUrl' => '/apps/bookingcheck/pub/o/mandat-x',
			'kind_bad' => 'ignored',
		]);
		$this->assertSame('New Name', $out->getName());
		$this->assertSame('r2', $out->getRef());
		$this->assertSame('private', $out->getKind());
		$this->assertSame(42, $out->getPcCustId());
		$this->assertSame('/apps/bookingcheck/pub/o/mandat-x', $out->getBookUrl());
		$this->assertNotNull($out->getUpdatedAt());
	}

	public function testUpdateNullablesAndSelfRef(): void
	{
		$org = new Org();
		$org->setId(3);
		$org->setRef('r1');
		$self = new Org();
		$self->setId(3); // same row holding the ref — not a conflict
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturn($org);
		$orgs->method('findByRef')->willReturn($self);
		$svc = $this->orgSvc($orgs);
		$out = $svc->update('staff', 3, ['ref' => 'r1', 'pcCustId' => null, 'bookUrl' => '']);
		$this->assertSame('r1', $out->getRef());        // self-ref allowed
		$this->assertNull($out->getPcCustId());          // null clears
		$this->assertNull($out->getBookUrl());           // '' → cleared
		// clearing the ref entirely
		$out2 = $svc->update('staff', 3, ['ref' => '']);
		$this->assertNull($out2->getRef());
		// invalid kind silently ignored (never corrupts)
		$out3 = $svc->update('staff', 3, ['kind' => 'hacker']);
		$this->assertNotSame('hacker', $out3->getKind());
	}

	public function testUpdateConflictingRefAndBadName(): void
	{
		$org = new Org();
		$org->setId(3);
		$other = new Org();
		$other->setId(4);
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturn($org);
		$orgs->method('findByRef')->willReturn($other);
		$svc = $this->orgSvc($orgs);
		try {
			$svc->update('staff', 3, ['ref' => 'taken-ref']);
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['ref' => 'taken'], $e->getFields());
		}
		try {
			$svc->update('staff', 3, ['name' => '']);
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['name' => 'required'], $e->getFields());
		}
		// unique race on update → same field error
		$orgs2 = $this->createMock(OrgMapper::class);
		$orgs2->method('find')->willReturn($org);
		$orgs2->method('findByRef')->willReturn(null);
		$orgs2->method('update')->willThrowException($this->uniq());
		try {
			$this->orgSvc($orgs2)->update('staff', 3, ['ref' => 'x']);
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['ref' => 'taken'], $e->getFields());
		}
	}

	public function testBookingUrlStrictAllowlist(): void
	{
		$svc = $this->orgSvc($this->createMock(OrgMapper::class));
		$this->assertNull($svc->validateBookingUrl(''));
		$this->assertNull($svc->validateBookingUrl('   '));
		$this->assertSame('/apps/bookingcheck/pub/o/x', $svc->validateBookingUrl(' /apps/bookingcheck/pub/o/x '));
		$this->assertSame('/index.php/apps/bookingcheck/pub/o/Ab_9-z/', $svc->validateBookingUrl('/index.php/apps/bookingcheck/pub/o/Ab_9-z/'));
		foreach (['https://evil.de/apps/bookingcheck/pub/o/x', '/apps/bookingcheck/pub/o/', '/apps/other/pub/o/x', '/apps/bookingcheck/pub/o/x/../y', 'javascript:alert(1)',
			'BAD/apps/bookingcheck/pub/o/x',   // ^ anchor: no leading-junk prefix
			'/apps/bookingcheck/pub/o/x ,',   // $ anchor: no trailing junk
			'/apps/bookingcheck/pub/o/x&y=1', // query-smuggled params
		] as $bad) {
			try {
				$svc->validateBookingUrl($bad);
				$this->fail("accepted $bad");
			} catch (ApiException $e) {
				$this->assertSame(400, $e->getStatus());
				$this->assertSame('BOOKING_URL_INVALID', $e->getErrorCode());
			}
		}
	}

	public function testSetAppEnabledLicenceGate(): void
	{
		$org = new Org();
		$org->setAppOn(false);
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturn($org);
		$lic = $this->createMock(LicenseService::class);
		$audit = $this->createMock(AuditService::class);

		// no slot → 402, org never flips, no update
		$lic->method('tryAcquirePortalSlot')->willReturn(false);
		$orgs->expects($this->never())->method('update');
		try {
			$this->orgSvc($orgs, $lic, $audit)->setAppEnabled('staff', 1, true);
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(402, $e->getStatus());
			$this->assertSame('NO_PORTAL_SLOT', $e->getErrorCode());
			$this->assertFalse($org->getAppOn());
		}
	}

	public function testSetAppEnabledOnOffAndIdempotent(): void
	{
		$org = new Org();
		$org->setAppOn(false);
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturn($org);
		$orgs->expects($this->exactly(2))->method('update')->with($org); // enable + disable, not the idempotent call
		$lic = $this->createMock(LicenseService::class);
		$lic->method('tryAcquirePortalSlot')->willReturn(true);
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->exactly(2))->method('log')
			->willReturnCallback(fn (string $a, string $act) => match ($act) {
				'org.app_enable', 'org.app_disable' => null,
				default => throw new \LogicException("unexpected $act"),
			});
		$svc = $this->orgSvc($orgs, $lic, $audit);
		$out = $svc->setAppEnabled('staff', 1, true);
		$this->assertTrue($out->getAppOn());
		$this->assertNotNull($out->getAppOnAt()); // enable stamp only on enable
		$out2 = $svc->setAppEnabled('staff', 1, true);  // idempotent early-return
		$this->assertTrue($out2->getAppOn());
		$out3 = $svc->setAppEnabled('staff', 1, false); // disable needs no slot
		$this->assertFalse($out3->getAppOn());
		$this->assertNotNull($out3->getUpdatedAt());
	}

	public function testOffboard(): void
	{
		$org = new Org();
		$org->setState('active');
		$orgs = $this->createMock(OrgMapper::class);
		$orgs->method('find')->willReturn($org);
		$orgs->expects($this->once())->method('update')->with($org);
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('log')->with('staff', 'org.offboard', 'org', 3);
		$out = $this->orgSvc($orgs, null, $audit)->offboard('staff', 3);
		$this->assertSame('offboarded', $out->getState());
		$this->assertNotNull($out->getUpdatedAt());
	}
}

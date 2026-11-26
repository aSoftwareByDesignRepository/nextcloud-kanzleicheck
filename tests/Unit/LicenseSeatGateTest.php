<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\LicenseState;
use OCA\KanzleiCheck\Db\LicenseStateMapper;
use OCA\KanzleiCheck\Db\MobileSeat;
use OCA\KanzleiCheck\Db\MobileSeatMapper;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\LicenseService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mobile-seat branch matrix (P6-08): status ladder, named-seat gate
 * (assign → rank → limit), hard assignment cap, revoke idempotence.
 */
class LicenseSeatGateTest extends TestCase
{
	private LicenseStateMapper&MockObject $states;
	private OrgMapper&MockObject $orgs;
	private IDBConnection&MockObject $db;
	private MobileSeatMapper&MockObject $seats;
	private IUserManager&MockObject $userManager;
	private AccessDoorService&MockObject $door;
	private AuditService&MockObject $audit;
	private LicenseService $svc;

	protected function setUp(): void
	{
		$this->states = $this->createMock(LicenseStateMapper::class);
		$this->orgs = $this->createMock(OrgMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->seats = $this->createMock(MobileSeatMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->door = $this->createMock(AccessDoorService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->svc = new LicenseService(
			$this->states, $this->orgs, $this->db,
			$this->seats, $this->userManager, $this->door, $this->audit,
		);
	}

	private function license(?int $seats = 2, string $validUntil = '2999-01-01'): LicenseState
	{
		$s = new LicenseState();
		$s->setValidUntil($validUntil);
		$s->setMobileSeats($seats ?? 0);
		return $s;
	}

	private function seat(int $id, string $uid, string $assignedAt): MobileSeat
	{
		$s = new MobileSeat();
		$s->setId($id);
		$s->setUserId($uid);
		$s->setAssignedBy('admin');
		$s->setAssignedAt(new \DateTimeImmutable($assignedAt));
		return $s;
	}

	public function testStatusLadder(): void
	{
		$this->states->method('load')->willReturnOnConsecutiveCalls(
			null,
			$this->license(2, '2999-01-01'),
			$this->license(2, '2000-01-01'),   // expired far beyond grace
			$this->license(2, (new \DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d')), // in 30d grace
		);
		$this->assertSame('none', $this->svc->status());
		$this->assertSame('valid', $this->svc->status());
		$this->assertSame('expired', $this->svc->status());
		$this->assertSame('grace', $this->svc->status());
	}

	public function testSeatGateLicenseLadder(): void
	{
		// No licence → LICENSE_REQUIRED before any seat lookup.
		$this->states->method('load')->willReturnOnConsecutiveCalls(null, $this->license(2, '2000-01-01'));
		$this->seats->expects($this->never())->method('findByUser');
		$this->assertSame('LICENSE_REQUIRED', $this->svc->seatGate('alice'));
		$this->assertSame('LICENSE_EXPIRED', $this->svc->seatGate('alice'));
	}

	public function testSeatGateNoSeatAssigned(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->seats->method('findByUser')->with('alice')->willReturn(null);
		$this->assertSame('NO_MOBILE_SEAT', $this->svc->seatGate('alice'));
	}

	public function testSeatGateWithinLimitAdmits(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$mine = $this->seat(2, 'alice', '2026-01-02');
		$this->seats->method('findByUser')->with('alice')->willReturn($mine);
		$this->seats->method('findAllOrdered')->willReturn([
			$this->seat(1, 'bob', '2026-01-01'), $mine,
		]);
		$this->assertSame('ok', $this->svc->seatGate('alice'));
	}

	public function testSeatGateOverRankBlocked(): void
	{
		// License shrink: 2 seats licensed, alice holds rank 3 → NO_MOBILE_SEAT.
		$this->states->method('load')->willReturn($this->license(2));
		$mine = $this->seat(3, 'alice', '2026-01-03');
		$this->seats->method('findByUser')->with('alice')->willReturn($mine);
		$this->seats->method('findAllOrdered')->willReturn([
			$this->seat(1, 'bob', '2026-01-01'),
			$this->seat(2, 'carol', '2026-01-02'),
			$mine,
		]);
		$this->assertSame('NO_MOBILE_SEAT', $this->svc->seatGate('alice'));
	}

	public function testSeatGateGraceAdmits(): void
	{
		$this->states->method('load')->willReturn(
			$this->license(2, (new \DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d')));
		$mine = $this->seat(1, 'alice', '2026-01-01');
		$this->seats->method('findByUser')->willReturn($mine);
		$this->seats->method('findAllOrdered')->willReturn([$mine]);
		$this->assertSame('ok', $this->svc->seatGate('alice'));
	}

	public function testSeatsListMarksOverLimitRows(): void
	{
		$this->states->method('load')->willReturn($this->license(1));
		$a = $this->seat(1, 'alice', '2026-01-01');
		$b = $this->seat(2, 'bob', '2026-01-02');
		$this->seats->method('findAllOrdered')->willReturn([$a, $b]);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice A');
		$this->userManager->method('get')->willReturnMap([['alice', $user], ['bob', null]]);
		$rows = $this->svc->seatsList();
		$this->assertSame('alice', $rows[0]['uid']);
		$this->assertSame('Alice A', $rows[0]['displayName']);
		$this->assertTrue($rows[0]['withinLimit']);
		$this->assertSame('bob', $rows[1]['displayName']); // falls back to uid
		$this->assertFalse($rows[1]['withinLimit']);
	}

	public function testAssignSeatRejectsWithoutLicense(): void
	{
		$this->states->method('load')->willReturn(null);
		$this->expectException(ApiException::class);
		$this->svc->assignSeat('alice', 'admin');
	}

	public function testAssignSeatRejectsUnknownUser(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->with('ghost')->willReturn(false);
		try {
			$this->svc->assignSeat('ghost', 'admin');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('VALIDATION_FAILED', $e->getErrorCode());
			$this->assertSame('unknown_user', $e->getFields()['uid']);
		}
	}

	public function testAssignSeatRejectsNonStaff(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->with('alice')->willReturn(true);
		$this->door->method('isStaff')->with('alice')->willReturn(false);
		try {
			$this->svc->assignSeat('alice', 'admin');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('not_staff', $e->getFields()['uid']);
		}
	}

	public function testAssignSeatIsIdempotent(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->willReturn(true);
		$existing = $this->seat(9, 'alice', '2026-01-01');
		$this->seats->method('findByUser')->with('alice')->willReturn($existing);
		$this->db->expects($this->never())->method('beginTransaction');
		$this->assertSame($existing, $this->svc->assignSeat('alice', 'admin'));
	}

	public function testAssignSeatHardCap(): void
	{
		$this->states->method('load')->willReturn($this->license(1));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->willReturn(true);
		$this->seats->method('findByUser')->willReturn(null);
		$this->seats->method('countAll')->willReturn(1); // cap reached
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('EQ');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchOne')->willReturn('1');
		$qb->method('executeQuery')->willReturn($res);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->seats->expects($this->never())->method('insert');
		try {
			$this->svc->assignSeat('alice', 'admin');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('SEAT_LIMIT', $e->getErrorCode());
		}
	}

	public function testAssignSeatHappyPathAudits(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->willReturn(true);
		$this->seats->method('findByUser')->willReturn(null);
		$this->seats->method('countAll')->willReturn(0);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('EQ');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchOne')->willReturn('2');
		$qb->method('executeQuery')->willReturn($res);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->seats->method('insert')->willReturnCallback(function (MobileSeat $s) {
			$s->setId(5);
			return $s;
		});
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'seat.assign', 'identity', 'alice');
		$seat = $this->svc->assignSeat('alice', 'admin');
		$this->assertSame('alice', $seat->getUserId());
		$this->assertSame('admin', $seat->getAssignedBy());
	}

	public function testRevokeSeatIdempotence(): void
	{
		$this->seats->method('find')->with(7)->willReturnOnConsecutiveCalls($this->seat(7, 'alice', '2026-01-01'), null);
		$this->seats->expects($this->once())->method('delete');
		$this->svc->revokeSeat(7, 'admin');
		$this->expectException(NotFoundException::class);
		$this->svc->revokeSeat(7, 'admin');
	}
}

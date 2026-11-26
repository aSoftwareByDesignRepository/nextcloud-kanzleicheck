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
use OCA\KanzleiCheck\License\Kzc2Codec;
use OCA\KanzleiCheck\Config\VendorPublicKey;
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
 * Mutation-hunter on LicenseService: apply() codec path, grace boundary
 * arithmetic, portal-slot transaction legs, rank ordering, seatsList field
 * mapping, assignSeat rollback/recovery, revoke audit detail.
 */
class LicenseServiceMutationTest extends TestCase
{
	private LicenseStateMapper&MockObject $states;
	private OrgMapper&MockObject $orgs;
	private IDBConnection&MockObject $db;
	private MobileSeatMapper&MockObject $seats;
	private IUserManager&MockObject $userManager;
	private AccessDoorService&MockObject $door;
	private AuditService&MockObject $audit;
	private LicenseService $svc;

	private static string $secretKey;

	public static function setUpBeforeClass(): void
	{
		$kp = sodium_crypto_sign_keypair();
		self::$secretKey = sodium_crypto_sign_secretkey($kp);
		putenv('KZC_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($kp)));
	}

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

	private function license(?int $seats = 2, ?string $validUntil = '2999-01-01', ?int $clients = 25): LicenseState
	{
		$s = new LicenseState();
		$s->setValidUntil($validUntil);
		$s->setMobileSeats($seats ?? 0);
		$s->setPortalClients($clients ?? 0);
		return $s;
	}

	private function seat(int $id, string $uid, string $assignedAt = '2026-01-01'): MobileSeat
	{
		$s = new MobileSeat();
		$s->setId($id);
		$s->setUserId($uid);
		$s->setAssignedBy('admin');
		$s->setAssignedAt(new \DateTimeImmutable($assignedAt));
		return $s;
	}

	private function wireKey(array $payload): string
	{
		$canonical = Kzc2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($canonical, self::$secretKey);
		return 'KZC2.' . VendorPublicKey::base64urlEncode($canonical) . '.' . VendorPublicKey::base64urlEncode($sig);
	}

	private function validPayload(array $over = []): array
	{
		return array_merge([
			'v' => 2, 'product' => 'kanzleicheck', 'customerId' => 'cust-9',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-06-01',
			'mobileSeats' => 7, 'portalClients' => 40,
		], $over);
	}

	/** Shared query-builder stub returning $cap from the licence row lock. */
	private function lockQb(mixed $cap): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('EQ');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchOne')->willReturn($cap);
		$qb->method('executeQuery')->willReturn($res);
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	// ── apply() ──

	public function testApplyValidKeyMapsAndSaves(): void
	{
		$this->states->expects($this->once())->method('save')
			->willReturnCallback(function (LicenseState $s) {
				$this->assertSame('cust-9', $s->getCustomerId());
				$this->assertSame('2027-06-01', $s->getValidUntil());
				$this->assertSame(7, $s->getMobileSeats());
				$this->assertSame(40, $s->getPortalClients());
				$this->assertSame('admin', $s->getAppliedBy());
				$this->assertNotNull($s->getAppliedAt());
				$this->assertSame(64, strlen((string)$s->getKeyFingerprint())); // sha256 hex
				$this->assertNotEmpty($s->getSignatureB64());
				$this->assertNotEmpty($s->getPayloadJson());
				return $s;
			});
		$this->svc->apply($this->wireKey($this->validPayload()), 'admin');
	}

	public function testApplyInvalidWireRejected400(): void
	{
		$this->states->expects($this->never())->method('save');
		try {
			$this->svc->apply('KZC2.garbage.sig', 'admin');
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame('VALIDATION_FAILED', $e->getErrorCode());
			$this->assertSame(400, $e->getStatus());
			$this->assertSame(Kzc2Codec::classifyError('KZC2.garbage.sig'), $e->getMessage());
		}
	}

	// ── status() boundaries ──

	public function testStatusGraceBoundaries(): void
	{
		$today = new \DateTimeImmutable('today');
		$this->states->method('load')->willReturnOnConsecutiveCalls(
			$this->license(2, $today->format('Y-m-d')),            // valid today
			$this->license(2, $today->modify('-30 days')->format('Y-m-d')), // grace day 30 (inclusive)
			$this->license(2, $today->modify('-31 days')->format('Y-m-d')), // expired
		);
		$this->assertSame('valid', $this->svc->status());
		$this->assertSame('grace', $this->svc->status());
		$this->assertSame('expired', $this->svc->status());
	}

	// ── tryAcquirePortalSlot ──

	public function testPortalSlotAcquireLegs(): void
	{
		// cap row missing → false, still commits
		$this->lockQb(false);
		$this->db->expects($this->once())->method('commit');
		$this->assertFalse($this->svc->tryAcquirePortalSlot());

		// used == cap → false
		$this->orgs = $this->createMock(OrgMapper::class);
		$this->orgs->method('countAppEnabled')->willReturn(3);
		$this->db = $this->createMock(IDBConnection::class);
		$svc = new LicenseService($this->states, $this->orgs, $this->db,
			$this->seats, $this->userManager, $this->door, $this->audit);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('EQ');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchOne')->willReturnOnConsecutiveCalls('3', '4');
		$qb->method('executeQuery')->willReturn($res);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->assertFalse($svc->tryAcquirePortalSlot()); // used == cap → false
		$this->assertTrue($svc->tryAcquirePortalSlot());  // used < cap → true
	}

	public function testPortalSlotQueryFailureRollsBack(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('EQ');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeQuery')->willThrowException(new \RuntimeException('db gone'));
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(\RuntimeException::class);
		$this->svc->tryAcquirePortalSlot();
	}

	public function testPortalClientsTotal(): void
	{
		$this->states->method('load')->willReturnOnConsecutiveCalls(null, $this->license(2, '2999-01-01', 33));
		$this->assertSame(0, $this->svc->portalClientsTotal());
		$this->assertSame(33, $this->svc->portalClientsTotal());
	}

	// ── seatRanks ordering ──

	public function testSeatRankTieBreakById(): void
	{
		$this->states->method('load')->willReturn($this->license(1)); // limit 1
		$this->seats->method('findByUser')->willReturn($this->seat(9, 'bob'));
		// same assignedAt — id order decides: id 5 ranks first, id 9 second → over limit
		$this->seats->method('findAllOrdered')->willReturn([
			$this->seat(9, 'bob'), $this->seat(5, 'amy'),
		]);
		$g = $this->svc->gateState('bob');
		$this->assertFalse($g['seatWithinLimit']); // rank 2 > limit 1
		$this->assertTrue($g['seatAssigned']);
		$this->assertSame(1, $g['mobileSeats']);
		$this->assertSame('valid', $g['licenseStatus']);
	}

	public function testGateStateNoSeat(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->seats->method('findByUser')->willReturn(null);
		$g = $this->svc->gateState('ghost');
		$this->assertFalse($g['seatAssigned']);
		$this->assertFalse($g['seatWithinLimit']);
	}

	// ── seatsList fields ──

	public function testSeatsListFieldsAndFallbacks(): void
	{
		$this->states->method('load')->willReturn($this->license(1));
		$gone = $this->seat(5, 'ghost');       // user deleted → uid fallback
		$noDate = $this->seat(6, 'alice');
		$noDate->setAssignedAt(null);
		$this->seats->method('findAllOrdered')->willReturn([$gone, $noDate]);
		$u = $this->createMock(IUser::class);
		$u->method('getDisplayName')->willReturn('Alice A');
		$this->userManager->method('get')->willReturnMap([['ghost', null], ['alice', $u]]);
		$rows = $this->svc->seatsList();
		$this->assertSame('ghost', $rows[0]['displayName']); // fallback to uid
		$this->assertSame('Alice A', $rows[1]['displayName']);
		$this->assertNull($rows[1]['assignedAt']);
		// rank sort: null assignedAt precedes a dated one → noDate=rank1, gone=rank2
		$this->assertFalse($rows[0]['withinLimit']); // rank2 > limit 1
		$this->assertTrue($rows[1]['withinLimit']);  // rank1 ≤ 1
		$this->assertSame('admin', $rows[0]['assignedBy']);
	}

	// ── assignSeat legs ──

	public function testAssignSeatStatusCodes(): void
	{
		$this->states->method('load')->willReturn(null);
		try {
			$this->svc->assignSeat('alice', 'admin');
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame('LICENSE_REQUIRED', $e->getErrorCode());
			$this->assertSame(400, $e->getStatus());
		}
	}

	public function testAssignSeatTrimsUidAndRejectsBlank(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->expects($this->once())->method('userExists')->with('alice')->willReturn(false);
		try {
			$this->svc->assignSeat('  alice  ', 'admin');
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame(['uid' => 'unknown_user'], $e->getFields());
			$this->assertSame(400, $e->getStatus());
		}
		// whitespace-only → empty after trim → same rejection without userExists call
		try {
			$this->svc->assignSeat(" \t ", 'admin');
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame('VALIDATION_FAILED', $e->getErrorCode());
		}
	}

	public function testAssignSeatInsertRaceRecoversWinner(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->willReturn(true);
		// first lookup empty, post-rollback lookup finds the concurrent winner's row
		$winner = $this->seat(11, 'alice');
		$this->seats->method('findByUser')->willReturnOnConsecutiveCalls(null, $winner);
		$this->seats->method('countAll')->willReturn(0);
		$this->lockQb('2');
		$this->seats->method('insert')->willThrowException(new \RuntimeException('unique'));
		$this->db->expects($this->once())->method('rollBack');
		$out = $this->svc->assignSeat('alice', 'admin');
		$this->assertSame($winner, $out); // loser returns the winner's row
	}

	public function testAssignSeatInsertFailureNoWinnerIs500(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->willReturn(true);
		$this->seats->method('findByUser')->willReturn(null);
		$this->seats->method('countAll')->willReturn(0);
		$this->lockQb('2');
		$this->seats->method('insert')->willThrowException(new \RuntimeException('db'));
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->svc->assignSeat('alice', 'admin');
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame('SEAT_ASSIGN_FAILED', $e->getErrorCode());
			$this->assertSame(500, $e->getStatus());
		}
	}

	public function testAssignSeatNonStaffFieldError(): void
	{
		$this->states->method('load')->willReturn($this->license(2));
		$this->userManager->method('userExists')->willReturn(true);
		$this->door->method('isStaff')->with('alice')->willReturn(false);
		try {
			$this->svc->assignSeat('alice', 'admin');
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame(['uid' => 'not_staff'], $e->getFields());
		}
	}

	// ── revokeSeat ──

	public function testRevokeSeatAuditDetail(): void
	{
		$seat = $this->seat(9, 'alice');
		$this->seats->method('find')->willReturn($seat);
		$this->seats->expects($this->once())->method('delete')->with($seat);
		$this->audit->expects($this->once())->method('log')
			->with('admin', 'seat.revoke', 'identity', 'alice', ['seatId' => 9]);
		$this->svc->revokeSeat(9, 'admin');
	}
}

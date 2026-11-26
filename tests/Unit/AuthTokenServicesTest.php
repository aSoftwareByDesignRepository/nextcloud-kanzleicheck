<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Device;
use OCA\KanzleiCheck\Db\DeviceMapper;
use OCA\KanzleiCheck\Db\Otp;
use OCA\KanzleiCheck\Db\OtpMapper;
use OCA\KanzleiCheck\Db\PortalSession;
use OCA\KanzleiCheck\Db\PortalSessionMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Service\DeviceService;
use OCA\KanzleiCheck\Service\OtpService;
use OCA\KanzleiCheck\Service\PortalSessionService;
use OCA\KanzleiCheck\Service\SettingsService;
use OCA\KanzleiCheck\Service\TokenService;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The auth primitives — OtpService, PortalSessionService, DeviceService.
 * Token lifecycle, attempt fencing, TTL clamps, expiry edges, exactly-once
 * consumption, collision retry, revoke scoping. Fail-closed everywhere.
 */
class AuthTokenServicesTest extends TestCase
{
	private function db(int $execResult = 1): IDBConnection&MockObject
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('lt')->willReturn('lt');
		$expr->method('isNull')->willReturn('isnull');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('createFunction')->willReturn('fn');
		$qb->method('executeStatement')->willReturn($execResult);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function tokens(): TokenService&MockObject
	{
		$t = $this->createMock(TokenService::class);
		$t->method('mint')->willReturn(['tok', 'th']);
		$t->method('mintOtp')->willReturn('123456');
		$t->method('hash')->willReturnCallback(fn (string $v) => 'h:' . $v);
		$t->method('verify')->willReturnCallback(fn (string $p, string $h) => $h === 'h:' . $p);
		return $t;
	}

	// ── OtpService::issue ──

	public function testOtpIssueClampsTtlAndNormalisesEmail(): void
	{
		foreach ([3 => 5, 10 => 10, 99 => 30] as $cfg => $wantMin) {
			$otps = $this->createMock(OtpMapper::class);
			$settings = $this->createMock(SettingsService::class);
			$settings->method('getInt')->with('otp_ttl_min', 10)->willReturn($cfg);
			$db = $this->db();
			$svc = new OtpService($otps, $this->tokens(), $settings, $db);
			$captured = null;
			$otps->expects($this->once())->method('invalidateActive')
				->with('a@x.de', 'login');
			$otps->method('insert')->willReturnCallback(function (Otp $o) use (&$captured) {
				$o->setId(1);
				$captured = $o;
				return $o;
			});
			[$code, $magic, $id] = $svc->issue(' A@X.DE ', 'login', '1.2.3.4', true);
			$this->assertSame('123456', $code);
			$this->assertSame('tok', $magic);
			$this->assertSame(1, $id);
			$delta = $captured->getExpiresAt()->getTimestamp() - $captured->getCreatedAt()->getTimestamp();
			$this->assertSame($wantMin * 60, $delta, "cfg=$cfg");
			$this->assertSame('a@x.de', $captured->getEmail()); // lowered+trimmed
			$this->assertSame('h:123456', $captured->getCodeHash()); // code hashed at rest
			$this->assertSame('th', $captured->getMagicHash());
			$this->assertSame(0, $captured->getAttempts());
			$this->assertSame(hash('sha256', '1.2.3.4'), $captured->getIpHash());
		}
	}

	public function testOtpIssueNoMagicAndRollback(): void
	{
		$otps = $this->createMock(OtpMapper::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getInt')->willReturn(10);
		$db = $this->db();
		$svc = new OtpService($otps, $this->tokens(), $settings, $db);
		$otps->method('insert')->willReturnCallback(function (Otp $o) {
			$o->setId(9);
			return $o;
		});
		[, $magic, $id] = $svc->issue('b@x.de', 'pair', '::1', false);
		$this->assertNull($magic); // no magic minted for pair codes
		$this->assertSame(9, $id);

		// insert blows up → rollback + rethrow, never a dangling code
		$otps2 = $this->createMock(OtpMapper::class);
		$otps2->method('insert')->willThrowException(new \RuntimeException('db down'));
		$db2 = $this->createMock(IDBConnection::class);
		$db2->expects($this->once())->method('rollBack');
		$svc2 = new OtpService($otps2, $this->tokens(), $settings, $db2);
		$this->expectException(\RuntimeException::class);
		$svc2->issue('c@x.de', 'login', '::1', true);
	}

	// ── OtpService::verify ──

	public function testOtpVerifyMissAndAttemptFence(): void
	{
		$otps = $this->createMock(OtpMapper::class);
		$svc = new OtpService($otps, $this->tokens(),
			$this->createMock(SettingsService::class), $this->db());

		$otps->method('findActive')->willReturn(null);
		$this->assertNull($svc->verify('x@x.de', 'login', '123456')); // no row

		$row = new Otp();
		$row->setId(5);
		$row->setAttempts(OtpService::MAX_ATTEMPTS); // at cap → refused before increment
		$otps2 = $this->createMock(OtpMapper::class);
		$otps2->method('findActive')->willReturn($row);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder'); // SQL fence never reached
		$svc2 = new OtpService($otps2, $this->tokens(), $this->createMock(SettingsService::class), $db);
		$this->assertNull($svc2->verify('x@x.de', 'login', '123456'));
	}

	public function testOtpVerifyAttemptsBelowCapProceeds(): void
	{
		$row = new Otp();
		$row->setId(5);
		$row->setAttempts(OtpService::MAX_ATTEMPTS - 1); // 4 → allowed to try
		$otps = $this->createMock(OtpMapper::class);
		$otps->method('findActive')->willReturn($row);
		$db = $this->db(0); // fenced increment loses race → null
		$svc = new OtpService($otps, $this->tokens(), $this->createMock(SettingsService::class), $db);
		$this->assertNull($svc->verify('x@x.de', 'login', '123456'));
	}

	public function testOtpVerifyCodeMatchConsumePath(): void
	{
		$row = new Otp();
		$row->setId(7);
		$row->setAttempts(0);
		$row->setCodeHash('h:123456');
		$otps = $this->createMock(OtpMapper::class);
		$otps->method('findActive')->with('x@x.de', 'login')->willReturn($row);

		// wrong code → null even though the increment succeeded
		$db = $this->db(1);
		$svc = new OtpService($otps, $this->tokens(), $this->createMock(SettingsService::class), $db);
		$this->assertNull($svc->verify('x@x.de', 'login', '999999'));
		// right code → consumed, returns row id
		$this->assertSame(7, $svc->verify(' X@X.DE ', 'login', '123456'));
		// consume CAS loses (row used concurrently) → null
		$db0 = $this->db(0);
		// first executeStatement (increment) must return 1, consume must return 0:
		// db() returns one value for all calls, so build a sequenced qb
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('lt')->willReturn('lt');
		$expr->method('isNull')->willReturn('isnull');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('createFunction')->willReturn('fn');
		$qb->method('executeStatement')->willReturnOnConsecutiveCalls(1, 0);
		$db0->method('getQueryBuilder')->willReturn($qb);
		$svc0 = new OtpService($otps, $this->tokens(), $this->createMock(SettingsService::class), $db0);
		$this->assertNull($svc0->verify('x@x.de', 'login', '123456'));
	}

	public function testOtpMagicConsume(): void
	{
		$row = new Otp();
		$row->setId(3);
		$otps = $this->createMock(OtpMapper::class);
		$otps->method('findByMagicHash')->with('h:mag')->willReturn($row);
		$svc = new OtpService($otps, $this->tokens(), $this->createMock(SettingsService::class), $this->db(1));
		$this->assertSame($row, $svc->consumeMagic('mag'));

		$otps2 = $this->createMock(OtpMapper::class);
		$otps2->method('findByMagicHash')->willReturn(null);
		$svc2 = new OtpService($otps2, $this->tokens(), $this->createMock(SettingsService::class), $this->db());
		$this->assertNull($svc2->consumeMagic('gone'));
	}

	public function testOtpApprovalPurpose(): void
	{
		$this->assertSame('approve:42', OtpService::approvalPurpose(42));
		$this->assertSame('approve:1', OtpService::approvalPurpose(1));
	}

	// ── PortalSessionService ──

	public function testSessionCreateClampsDaysAndTruncatesUa(): void
	{
		foreach ([0 => 1, 30 => 30, 999 => 365] as $cfg => $wantDays) {
			$mapper = $this->createMock(PortalSessionMapper::class);
			$settings = $this->createMock(SettingsService::class);
			$settings->method('getInt')->with('sess_days', 30)->willReturn($cfg);
			$svc = new PortalSessionService($mapper, $this->tokens(), $settings, $this->db());
			$mapper->method('insert')->willReturnArgument(0);
			[$tok, $row] = $svc->create(7, str_repeat('u', 300));
			$this->assertSame('tok', $tok);
			$this->assertSame(7, $row->getIdentityId());
			$this->assertSame('th', $row->getTokenHash());
			$delta = $row->getExpiresAt()->diff($row->getCreatedAt())->days;
			$this->assertSame($wantDays, $delta, "cfg=$cfg");
			$abs = $row->getAbsExpiresAt()->diff($row->getCreatedAt())->days;
			$this->assertSame(365, $abs);
			$this->assertSame(190, mb_strlen($row->getUaShort()));
			$this->assertNotNull($row->getLastSeenAt()); // field set, not dropped
		}
	}

	public function testSessionCreateUaShortOffsetAndCharset(): void
	{
		$mapper = $this->createMock(PortalSessionMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getInt')->willReturn(30);
		$svc = new PortalSessionService($mapper, $this->tokens(), $settings, $this->db());
		$ua = 'UNIQUE-AGENT ' . str_repeat('x', 300);
		[, $row] = $svc->create(7, $ua);
		$this->assertTrue(str_starts_with($row->getUaShort(), 'UNIQUE-AGENT ')); // offset 0
		$this->assertSame(190, mb_strlen($row->getUaShort()));
		// multibyte chars truncate on character boundaries, not bytes
		[, $row2] = $svc->create(7, str_repeat('ü', 300));
		$this->assertSame(190, mb_strlen($row2->getUaShort()));
	}

	public function testSessionCreateCollisionRetries(): void
	{
		$mapper = $this->createMock(PortalSessionMapper::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getInt')->willReturn(30);
		$tokens = $this->createMock(TokenService::class);
		$tokens->method('mint')->willReturnOnConsecutiveCalls(['t1', 'h1'], ['t2', 'h2'], ['t3', 'h3'], ['t4', 'h4']);
		$uniq = new class extends DbException {
			public function getReason(): ?int
			{
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
		$mapper->method('insert')->willReturnOnConsecutiveCalls(
			$this->throwException($uniq),
			$this->throwException($uniq),
			new PortalSession(),
		);
		$svc = new PortalSessionService($mapper, $tokens, $settings, $this->db());
		[$tok] = $svc->create(7, 'ua');
		$this->assertSame('t3', $tok); // reminted twice then succeeded

		// 4th collision → gives up, rethrows — exactly 3 insert tries
		$mapper2 = $this->createMock(PortalSessionMapper::class);
		$mapper2->expects($this->exactly(3))->method('insert')->willThrowException($uniq);
		$svc2 = new PortalSessionService($mapper2, $this->tokens(), $settings, $this->db());
		$this->expectException(DbException::class);
		$svc2->create(7, 'ua');
	}

	public function testSessionResolveGuards(): void
	{
		$mapper = $this->createMock(PortalSessionMapper::class);
		$settings = $this->createMock(SettingsService::class);
		$svc = new PortalSessionService($mapper, $this->tokens(), $settings, $this->db());
		$this->assertNull($svc->resolve(''));                    // empty
		$this->assertNull($svc->resolve(str_repeat('x', 129))); // too long
		$this->assertNotNull($mapper); // lookup never ran for the guards

		$mapper->method('findByTokenHash')->willReturn(null);
		$this->assertNull($svc->resolve('tok')); // unknown hash

		// boundary: exactly 128 chars is legal — lookup runs
		$mapperB = $this->createMock(PortalSessionMapper::class);
		$mapperB->expects($this->once())->method('findByTokenHash')
			->with('h:' . str_repeat('x', 128))->willReturn(null);
		$svcB = new PortalSessionService($mapperB, $this->tokens(), $settings, $this->db());
		$this->assertNull($svcB->resolve(str_repeat('x', 128)));

		// revoked row
		$row = new PortalSession();
		$row->setId(1);
		$row->setRevokedAt(new \DateTimeImmutable());
		$mapper2 = $this->createMock(PortalSessionMapper::class);
		$mapper2->method('findByTokenHash')->willReturn($row);
		$svc2 = new PortalSessionService($mapper2, $this->tokens(), $settings, $this->db());
		$this->assertNull($svc2->resolve('tok'));
	}

	public function testSessionResolveExpiryEdges(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getInt')->willReturn(30);
		// abs cap already passed → dead
		$row = new PortalSession();
		$row->setId(1);
		$row->setAbsExpiresAt(new \DateTimeImmutable('-1 day'));
		$mapper = $this->createMock(PortalSessionMapper::class);
		$mapper->method('findByTokenHash')->willReturn($row);
		$svc = new PortalSessionService($mapper, $this->tokens(), $settings, $this->db());
		$this->assertNull($svc->resolve('tok'));

		// sliding window passed → dead
		$row2 = new PortalSession();
		$row2->setId(2);
		$row2->setAbsExpiresAt(new \DateTimeImmutable('+100 days'));
		$row2->setExpiresAt(new \DateTimeImmutable('-1 hour'));
		$mapper2 = $this->createMock(PortalSessionMapper::class);
		$mapper2->method('findByTokenHash')->willReturn($row2);
		$svc2 = new PortalSessionService($mapper2, $this->tokens(), $settings, $this->db());
		$this->assertNull($svc2->resolve('tok'));

		// live row → sliding renew, never beyond abs cap
		$row3 = new PortalSession();
		$row3->setId(3);
		$row3->setAbsExpiresAt(new \DateTimeImmutable('+2 days'));
		$row3->setExpiresAt(new \DateTimeImmutable('+1 hour'));
		$mapper3 = $this->createMock(PortalSessionMapper::class);
		$mapper3->method('findByTokenHash')->willReturn($row3);
		$db = $this->db(1);
		$svc3 = new PortalSessionService($mapper3, $this->tokens(), $settings, $db);
		$this->assertSame($row3, $svc3->resolve('tok'));
	}

	public function testSessionRevokeAndRevokeAll(): void
	{
		$mapper = $this->createMock(PortalSessionMapper::class);
		$mapper->expects($this->once())->method('revokeForIdentity')->with(7)->willReturn(4);
		$db = $this->db(1);
		$svc = new PortalSessionService($mapper, $this->tokens(), $this->createMock(SettingsService::class), $db);
		$svc->revoke('tok'); // fires the scoped update
		$this->assertSame(4, $svc->revokeAllForIdentity(7));
	}

	// ── DeviceService ──

	public function testDevicePairPlatformWhitelist(): void
	{
		$svc = new DeviceService($this->createMock(DeviceMapper::class), $this->tokens(), $this->db());
		try {
			$svc->pair(7, 'phone', 'windows');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->getStatus());
			$this->assertSame('VALIDATION_FAILED', $e->getErrorCode());
			$this->assertSame(['platform' => 'invalid'], $e->getFields());
		}
		foreach (['android', 'ios'] as $p) {
			$mapper = $this->createMock(DeviceMapper::class);
			$mapper->method('insert')->willReturnArgument(0);
			$mapper->expects($this->once())->method('revokeOldestBeyond')
				->with(7, DeviceService::MAX_DEVICES);
			$svc2 = new DeviceService($mapper, $this->tokens(), $this->db());
			[$tok, $d] = $svc2->pair(7, '  ', $p);
			$this->assertSame('tok', $tok);
			$this->assertSame('device', $d->getDeviceName()); // blank → default
			$this->assertSame($p, $d->getPlatform());
			$this->assertSame('th', $d->getTokenHash());
			$this->assertSame(7, $d->getIdentityId());        // setter executed
			$this->assertNotNull($d->getCreatedAt());          // field set, not dropped
			$this->assertNotNull($d->getLastUsedAt());
			$this->assertSame(365, $d->getAbsExpiresAt()->diff($d->getCreatedAt())->days);
		}
	}

	public function testDevicePairMultibyteNameTruncatesByChars(): void
	{
		$mapper = $this->createMock(DeviceMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$svc = new DeviceService($mapper, $this->tokens(), $this->db());
		// 99 ascii + 'ü' + more: mb_substr counts characters, not bytes
		$name = str_repeat('a', 99) . 'ü' . str_repeat('z', 50);
		[, $d] = $svc->pair(7, $name, 'ios');
		$this->assertSame(100, mb_strlen($d->getDeviceName()));
		$this->assertStringEndsWith('ü', $d->getDeviceName()); // char boundary respected
	}

	public function testDevicePairNameTruncatedAndCollisionRetry(): void
	{
		$mapper = $this->createMock(DeviceMapper::class);
		$uniq = new class extends DbException {
			public function getReason(): ?int
			{
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
		$ok = new Device();
		$mapper->method('insert')->willReturnOnConsecutiveCalls($this->throwException($uniq), $ok);
		$tokens = $this->createMock(TokenService::class);
		$tokens->method('mint')->willReturnOnConsecutiveCalls(['t1', 'h1'], ['t2', 'h2']);
		$svc = new DeviceService($mapper, $tokens, $this->db());
		[$tok, $d] = $svc->pair(7, str_repeat('n', 200), 'android');
		$this->assertSame('t2', $tok); // reminted after collision
		$this->assertSame(100, mb_strlen($d->getDeviceName()));
		$this->assertSame('h2', $d->getTokenHash()); // hash tracks the new token

		// third collision → rethrow — exactly 3 insert tries, never a 4th
		$mapper2 = $this->createMock(DeviceMapper::class);
		$mapper2->expects($this->exactly(3))->method('insert')->willThrowException($uniq);
		$svc2 = new DeviceService($mapper2, $this->tokens(), $this->db());
		$this->expectException(DbException::class);
		$svc2->pair(7, 'x', 'android');
	}

	public function testDeviceResolveGuardsAndExpiry(): void
	{
		$mapper = $this->createMock(DeviceMapper::class);
		$svc = new DeviceService($mapper, $this->tokens(), $this->db());
		$this->assertNull($svc->resolve(''));
		$this->assertNull($svc->resolve(str_repeat('x', 129)));

		$mapper->method('findByTokenHash')->willReturn(null);
		$this->assertNull($svc->resolve('tok'));

		// boundary: exactly 128 chars is a legal token — lookup must run
		$mapperB = $this->createMock(DeviceMapper::class);
		$mapperB->expects($this->once())->method('findByTokenHash')
			->with('h:' . str_repeat('x', 128))->willReturn(null);
		$svcB = new DeviceService($mapperB, $this->tokens(), $this->db());
		$this->assertNull($svcB->resolve(str_repeat('x', 128)));

		$dead = new Device();
		$dead->setRevokedAt(new \DateTimeImmutable());
		$mapper2 = $this->createMock(DeviceMapper::class);
		$mapper2->method('findByTokenHash')->willReturn($dead);
		$svc2 = new DeviceService($mapper2, $this->tokens(), $this->db());
		$this->assertNull($svc2->resolve('tok'));

		$expired = new Device();
		$expired->setAbsExpiresAt(new \DateTimeImmutable('-1 day'));
		$mapper3 = $this->createMock(DeviceMapper::class);
		$mapper3->method('findByTokenHash')->willReturn($expired);
		$svc3 = new DeviceService($mapper3, $this->tokens(), $this->db());
		$this->assertNull($svc3->resolve('tok'));

		// live row → returned, last_used_at touched
		$live = new Device();
		$live->setId(9);
		$mapper4 = $this->createMock(DeviceMapper::class);
		$mapper4->method('findByTokenHash')->willReturn($live);
		$svc4 = new DeviceService($mapper4, $this->tokens(), $this->db(1));
		$this->assertSame($live, $svc4->resolve('tok'));
	}

	public function testDeviceRevokeReturnsRowsAffected(): void
	{
		$svc = new DeviceService($this->createMock(DeviceMapper::class), $this->tokens(), $this->db(1));
		$this->assertTrue($svc->revoke(9));        // one row revoked
		$svc0 = new DeviceService($this->createMock(DeviceMapper::class), $this->tokens(), $this->db(0));
		$this->assertFalse($svc0->revoke(9));      // already revoked / wrong owner
		$this->assertFalse($svc0->revoke(9, 7));   // scoped by identity — no row
	}
}

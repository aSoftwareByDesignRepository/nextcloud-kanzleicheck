<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Device;
use OCA\KanzleiCheck\Db\DeviceMapper;
use OCA\KanzleiCheck\Db\Identity;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\Member;
use OCA\KanzleiCheck\Db\MemberMapper;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\NotifyMapper;
use OCA\KanzleiCheck\Db\OtpMapper;
use OCA\KanzleiCheck\Db\PortalSessionMapper;
use OCA\KanzleiCheck\Db\SyncBinding;
use OCA\KanzleiCheck\Db\SyncBindingMapper;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Service\ActorResolver;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\GdprService;
use OCA\KanzleiCheck\Service\SyncBindingService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GdprService — export + erase. Legal-grade: every PII field accounted
 * for, OTP delete keyed to the pre-anonymise email, message purge gated
 * on the flag, sync cascade BEFORE the anonymising transaction.
 */
class GdprServiceTest extends TestCase
{
	/** QB chain capturing which tables/statements ran. */
	private function db(array &$sql): IDBConnection&MockObject
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($a, $b) => "$a=" . json_encode($b));
		$expr->method('isNull')->willReturn('isnull');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('update')->willReturnCallback(function (string $t) use (&$sql, $qb) {
			$sql[] = "update:$t";
			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (string $t) use (&$sql, $qb) {
			$sql[] = "delete:$t";
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $k, $v) use (&$sql, $qb) {
			$sql[] = "set:$k=" . json_encode($v);
			return $qb;
		});
		$qb->method('where')->willReturnCallback(function (...$w) use (&$sql, $qb) {
			$sql = array_merge($sql, array_map('strval', $w));
			return $qb;
		});
		$qb->method('andWhere')->willReturnCallback(function (...$w) use (&$sql, $qb) {
			$sql = array_merge($sql, array_map('strval', $w));
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(fn ($v) => is_scalar($v) ? $v : '(param)');
		$qb->method('createFunction')->willReturn('fn');
		$qb->method('executeStatement')->willReturnCallback(function () use (&$sql) {
			$sql[] = 'exec';
			return 1;
		});
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([
			['id' => '1', 'conv_id' => '4', 'body' => 'hi', 'created_at' => 't1'],
			['id' => '2', 'conv_id' => '5', 'body' => 'hi2', 'created_at' => 't2'],
		]);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function identity(string $email = 'orig@x.de'): Identity
	{
		$i = new Identity();
		$i->setId(7);
		$i->setEmail($email);
		$i->setDisplayName('Max');
		$i->setStatus('active');
		$i->setNcUid('ncmax');
		$i->setNcUidMismatch(true);
		$i->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
		return $i;
	}

	public function testExportShapeAnd404(): void
	{
		$ids = $this->createMock(IdentityMapper::class);
		$ids->method('find')->willReturnMap([[7, $this->identity()], [9, null]]);
		$m = new Member();
		$m->setOrgId('5');
		$m->setRole('readonly');
		$m->setCreatedAt(new \DateTimeImmutable('2026-02-02'));
		$members = $this->createMock(MemberMapper::class);
		$members->method('findByIdentity')->willReturn([$m]);
		$d = new Device();
		$d->setDeviceName('phone');
		$d->setPlatform('ios');
		$d->setCreatedAt(new \DateTimeImmutable('2026-03-03'));
		$d->setLastUsedAt(new \DateTimeImmutable('2026-03-04'));
		$devices = $this->createMock(DeviceMapper::class);
		$devices->method('findByIdentity')->willReturn([$d]);
		$b = new SyncBinding();
		$b->setId(3);
		$b->setOrgId(5);
		$b->setPerms('ro');
		$b->setState('active');
		$b->setCreatedAt(new \DateTimeImmutable('2026-04-04'));
		$syncs = $this->createMock(SyncBindingMapper::class);
		$syncs->method('findByIdentity')->willReturn([$b]);
		$sql = [];
		$svc = new GdprService(
			$ids, $members, $this->createMock(MessageMapper::class),
			$this->createMock(NotifyMapper::class), $devices,
			$this->createMock(PortalSessionMapper::class), $this->createMock(OtpMapper::class),
			$this->db($sql), $this->createMock(AuditService::class), null, $syncs,
		);
		$out = $svc->exportIdentity(7);
		$iso = fn (string $d) => (new \DateTimeImmutable($d))->format('c');
		$this->assertSame(['id' => 7, 'email' => 'orig@x.de', 'displayName' => 'Max',
			'status' => 'active', 'ncUid' => 'ncmax', 'ncUidMismatch' => true,
			'createdAt' => $iso('2026-01-01')], $out['identity']);
		$this->assertSame([['orgId' => 5, 'role' => 'readonly', 'createdAt' => $iso('2026-02-02')]], $out['memberships']);
		$this->assertSame([['name' => 'phone', 'platform' => 'ios',
			'pairedAt' => $iso('2026-03-03'), 'lastUsedAt' => $iso('2026-03-04')]], $out['devices']);
		$this->assertSame([
			['id' => 1, 'convId' => 4, 'body' => 'hi', 'createdAt' => 't1'],
			['id' => 2, 'convId' => 5, 'body' => 'hi2', 'createdAt' => 't2'],
		], $out['messages']);
		$this->assertSame([['bindingId' => 3, 'orgId' => 5, 'perms' => 'ro', 'state' => 'active',
			'createdAt' => $iso('2026-04-04')]], $out['syncBindings']);
		// message query scoped to the portal actor, not raw id
		$this->assertContains('author="' . ActorResolver::portal(7) . '"', $sql);

		$this->expectException(NotFoundException::class);
		$svc->exportIdentity(9);
	}

	public function testEraseAnonymisesAndDropsCredentials(): void
	{
		$ids = $this->createMock(IdentityMapper::class);
		$ids->method('find')->willReturn($this->identity('orig@x.de'));
		$sessions = $this->createMock(PortalSessionMapper::class);
		$sessions->expects($this->once())->method('revokeForIdentity')->with(7);
		$devices = $this->createMock(DeviceMapper::class);
		$devices->expects($this->once())->method('revokeForIdentity')->with(7);
		$notify = $this->createMock(NotifyMapper::class);
		$notify->expects($this->once())->method('deleteForIdentity')->with(7);
		$syncBindings = $this->createMock(SyncBindingService::class);
		$syncBindings->expects($this->once())->method('cascadeErase')->with('staff', 7);
		$sql = [];
		$db = $this->db($sql);
		$db->expects($this->once())->method('commit');
		$db->expects($this->never())->method('rollBack');
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('log')
			->with('staff', 'identity.erase', 'identity', 7, ['messages' => true]);
		$svc = new GdprService(
			$ids, $this->createMock(MemberMapper::class), $this->createMock(MessageMapper::class),
			$notify, $devices, $sessions, $this->createMock(OtpMapper::class),
			$db, $audit, $syncBindings, null,
		);
		$svc->erase('staff', 7, true);
		// anonymise writes tombstone values keyed to the PK (never unique-collision)
		$this->assertContains('update:kzc_identity', $sql);
		$this->assertContains('set:email="erased-7@invalid"', $sql);
		$this->assertContains('set:status="erased"', $sql);
		$this->assertContains('set:display_name=' . json_encode('Gelöschter Kontakt'), $sql);
		// OTPs purged keyed to the ORIGINAL email (before anonymise)
		$this->assertContains('delete:kzc_otp', $sql);
		$this->assertContains('email="orig@x.de"', $sql);
		// messages purged when flag on
		$this->assertContains('delete:kzc_message', $sql);
		// all three statements actually executed (not just built)
		$this->assertSame(3, count(array_filter($sql, fn ($s) => $s === 'exec')));
	}

	public function testEraseKeepsMessagesWhenFlagOff(): void
	{
		$ids = $this->createMock(IdentityMapper::class);
		$ids->method('find')->willReturn($this->identity());
		$sql = [];
		$svc = new GdprService(
			$ids, $this->createMock(MemberMapper::class), $this->createMock(MessageMapper::class),
			$this->createMock(NotifyMapper::class), $this->createMock(DeviceMapper::class),
			$this->createMock(PortalSessionMapper::class), $this->createMock(OtpMapper::class),
			$this->db($sql), $this->createMock(AuditService::class), null, null,
		);
		$svc->erase('staff', 7, false);
		$this->assertContains('update:kzc_identity', $sql);
		$this->assertNotContains('delete:kzc_message', $sql); // audit trail kept
		$this->assertContains('delete:kzc_otp', $sql);        // credentials still dropped
		$this->assertSame(2, count(array_filter($sql, fn ($s) => $s === 'exec')));
	}

	public function testEraseUnknownIdentityRollsBack(): void
	{
		$ids = $this->createMock(IdentityMapper::class);
		$ids->method('find')->willReturn(null);
		$sql = [];
		$db = $this->db($sql);
		$db->expects($this->once())->method('rollBack');
		$svc = new GdprService(
			$ids, $this->createMock(MemberMapper::class), $this->createMock(MessageMapper::class),
			$this->createMock(NotifyMapper::class), $this->createMock(DeviceMapper::class),
			$this->createMock(PortalSessionMapper::class), $this->createMock(OtpMapper::class),
			$db, $this->createMock(AuditService::class), null, null,
		);
		$this->expectException(NotFoundException::class);
		$svc->erase('staff', 9, true);
	}
}

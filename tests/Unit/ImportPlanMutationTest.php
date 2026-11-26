<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Identity;
use OCA\KanzleiCheck\Db\Org;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\ImportService;
use OCA\KanzleiCheck\Service\OrgService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ImportService::plan() bucket matrix — every row lands in exactly one
 * bucket (NN-06), column-alias fallbacks, seen-uid duplicate marking,
 * classifier delegation, status arithmetic on the row model.
 */
class ImportPlanMutationTest extends TestCase
{
	private OrgService&MockObject $orgs;
	private IdentityService&MockObject $ids;
	private ImportService $svc;

	protected function setUp(): void
	{
		$this->orgs = $this->createMock(OrgService::class);
		$this->ids = $this->createMock(IdentityService::class);
		$this->svc = new ImportService();
	}

	private function row(array $r = []): array
	{
		return array_merge([
			'org_name' => 'Kanzlei A', 'org_ref' => '', 'email' => 'a@b.de',
			'display_name' => 'Max', 'nc_uid' => '',
		], $r);
	}

	public function testInvalidRowBuckets(): void
	{
		// empty org name → invalid_row
		$out = $this->svc->plan([$this->row(['org_name' => '  '])], $this->orgs, $this->ids);
		$this->assertSame('error', $out[0]['action']);
		$this->assertSame('invalid_row', $out[0]['code']);
		$this->assertSame(2, $out[0]['line']); // first data row is CSV line 2

		// bad email → invalid_row
		$out = $this->svc->plan([$this->row(['email' => 'not-mail'])], $this->orgs, $this->ids);
		$this->assertSame('invalid_row', $out[0]['code']);

		// row missing every column → invalid_row, never a crash on absent keys
		$out = $this->svc->plan([[]], $this->orgs, $this->ids);
		$this->assertSame('invalid_row', $out[0]['code']);
		$this->assertSame('', $out[0]['org']);
		$this->assertSame('', $out[0]['email']);
	}

	public function testActionMatrix(): void
	{
		$org = new Org();
		$identity = new Identity();
		// ref found + identity exists → reuse_org+reuse_identity
		$this->orgs->method('findByRefPublic')->willReturnMap([
			['R1', $org], ['R2', null],
		]);
		$this->ids->method('activeByEmail')->willReturnMap([
			['a@b.de', $identity], ['c@d.de', null],
		]);
		$out = $this->svc->plan([
			$this->row(['org_ref' => 'R1', 'email' => 'a@b.de']),   // reuse+reuse
			$this->row(['org_ref' => 'R2', 'email' => 'c@d.de']),   // create+create
		], $this->orgs, $this->ids);
		$this->assertSame('reuse_org+reuse_identity', $out[0]['action']);
		$this->assertSame('create_org+create_identity', $out[1]['action']);
		$this->assertNull($out[0]['code']);
	}

	public function testColumnAliasFallbacks(): void
	{
		// 'org'/'ref'/'name' shorthand aliases resolve like canonical keys
		$out = $this->svc->plan([[
			'org' => 'Alias Org', 'ref' => '', 'email' => 'x@y.de',
			'name' => 'N', 'nc_uid' => '',
		]], $this->orgs, $this->ids);
		$this->assertSame('Alias Org', $out[0]['org']);
		$this->assertSame('x@y.de', $out[0]['email']);
		$this->assertNotSame('error', $out[0]['action']);
	}

	public function testNcUidBuckets(): void
	{
		$this->orgs->method('findByRefPublic')->willReturn(null);
		$this->ids->method('activeByEmail')->willReturn(null);
		// no nc_uid column → no 'sync' key at all
		$out = $this->svc->plan([$this->row()], $this->orgs, $this->ids);
		$this->assertArrayNotHasKey('sync', $out[0]);
		$this->assertArrayNotHasKey('ncUid', $out[0]);

		// classifier absent → 'ok'
		$out = $this->svc->plan([$this->row(['nc_uid' => 'max'])], $this->orgs, $this->ids);
		$this->assertSame('ok', $out[0]['sync']);
		$this->assertSame('max', $out[0]['ncUid']);

		// classifier delegates + duplicate marks second occurrence only
		$out = $this->svc->plan([
			$this->row(['nc_uid' => 'max']),
			$this->row(['nc_uid' => 'max', 'email' => 'b@c.de']),
			$this->row(['nc_uid' => 'other', 'email' => 'd@e.de']),
		], $this->orgs, $this->ids,
			fn(string $uid, string $email): string => $uid === 'other' ? 'staff_uid' : 'ok');
		$this->assertSame('ok', $out[0]['sync']);             // first wins classifier
		$this->assertSame('uid_duplicate', $out[1]['sync']);  // second → duplicate
		$this->assertSame('staff_uid', $out[2]['sync']);      // classifier bucket passes through
	}

	public function testLineNumbersTrackRowPosition(): void
	{
		$this->orgs->method('findByRefPublic')->willReturn(null);
		$this->ids->method('activeByEmail')->willReturn(null);
		$out = $this->svc->plan([$this->row(), $this->row(), $this->row()], $this->orgs, $this->ids);
		$this->assertSame([2, 3, 4], array_column($out, 'line'));
	}
}

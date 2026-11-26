<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\DocRequest;
use OCA\KanzleiCheck\Db\DocRequestMapper;
use OCA\KanzleiCheck\Db\ReqFileMapper;
use OCA\KanzleiCheck\Db\ReqItem;
use OCA\KanzleiCheck\Db\ReqItemMapper;
use OCA\KanzleiCheck\Db\ReqRemindMapper;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\RequestService;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * RequestService::create — the txn-guarded write path: org-row FOR UPDATE
 * cap serialization, strict scalar guards on every request-array field,
 * unique-race collapse, rollback semantics.
 */
class RequestCreateTest extends TestCase
{
	private DocRequestMapper&MockObject $requests;
	private ReqItemMapper&MockObject $items;
	private AuditService&MockObject $audit;
	private IDBConnection&MockObject $db;
	private IQueryBuilder&MockObject $qb;
	private RequestService $svc;

	protected function setUp(): void
	{
		$this->requests = $this->createMock(DocRequestMapper::class);
		$this->items = $this->createMock(ReqItemMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=1');
		foreach (['select', 'from', 'where', 'forUpdate'] as $m) {
			$this->qb->method($m)->willReturn($this->qb);
		}
		$this->qb->method('expr')->willReturn($expr);
		$this->qb->method('createNamedParameter')->willReturn(':p');
		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->svc = new RequestService(
			$this->requests,
			$this->items,
			$this->createMock(ReqRemindMapper::class),
			$this->audit,
			$this->db,
			$this->createMock(UploadMapper::class),
			$this->createMock(ReqFileMapper::class),
		);
	}

	private function orgRowExists(bool $exists): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($exists ? 7 : false);
		$this->qb->method('executeQuery')->willReturn($result);
	}

	private function baseData(array $over = []): array
	{
		return array_merge([
			'title' => 'Please send',
			'items' => [['label' => 'Passport copy', 'kind' => 'upload']],
		], $over);
	}

	public function testHappyPathLocksOrgThenInserts(): void
	{
		$this->orgRowExists(true);
		$this->requests->expects($this->once())->method('countOpenByOrg')->willReturn(0);
		$this->requests->expects($this->once())->method('insert')
			->willReturnCallback(function (DocRequest $r) {
				$r->setId(42);
				return $r;
			});
		$this->items->expects($this->once())->method('insert')
			->willReturnCallback(fn (ReqItem $i) => $i);
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->audit->expects($this->once())->method('log')->with('staff', 'request.create', 'request', 42);
		$out = $this->svc->create('staff', 7, $this->baseData());
		$this->assertSame(42, $out->getId());
	}

	public function testCapInsideTxnThrowsAndRollsBack(): void
	{
		$this->orgRowExists(true);
		$this->requests->method('countOpenByOrg')->willReturn(RequestService::MAX_OPEN_PER_ORG);
		$this->requests->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->svc->create('staff', 7, $this->baseData());
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
		}
	}

	public function testGoneOrgIs404NotSilent(): void
	{
		$this->orgRowExists(false);
		$this->requests->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->svc->create('staff', 7, $this->baseData());
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(404, $e->getStatus());
		}
	}

	public function testNonScalarFieldsRejected(): void
	{
		$this->orgRowExists(true);
		$this->requests->method('countOpenByOrg')->willReturn(0);
		foreach ([
			[['title' => ['x']], 'title'],
			[['note' => ['x']], 'note'],
			[['taskId' => ['x']], 'taskId'],
			[['templateId' => '12abc'], 'templateId'],
			[['periodKey' => ['x']], 'periodKey'],
			[['reminderPolicy' => ['x']], 'reminderPolicy'],
		] as [$over, $field]) {
			try {
				$this->svc->create('staff', 7, $this->baseData($over));
				$this->fail("$field accepted");
			} catch (ValidationException $e) {
				$this->assertArrayHasKey($field, $e->getFields(), $field);
			}
		}
	}

	public function testItemLevelScalarsRejected(): void
	{
		$this->orgRowExists(true);
		$this->requests->method('countOpenByOrg')->willReturn(0);
		$this->requests->expects($this->once())->method('insert')
			->willReturnCallback(fn (DocRequest $r) => $r);
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->svc->create('staff', 7, $this->baseData([
				'items' => [['label' => ['x']]], // label array → "Array" never stored
			]));
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('items.label', $e->getFields());
		}
	}

	public function testNonArrayItemRejected(): void
	{
		try {
			$this->svc->create('staff', 7, $this->baseData(['items' => ['x']]));
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['items' => 'invalid'], $e->getFields());
		}
	}

	public function testUniqueRaceCollapsesToPeriodKeyField(): void
	{
		$this->orgRowExists(true);
		$this->requests->method('countOpenByOrg')->willReturn(0);
		$uniq = new class extends DbException {
			public function getReason(): ?int
			{
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
		$this->requests->method('insert')->willThrowException($uniq);
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->svc->create('staff', 7, $this->baseData());
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['periodKey' => 'duplicate'], $e->getFields());
		}
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\ConversationMapper;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\ReadMarkerMapper;
use OCA\KanzleiCheck\Db\ReqItemMapper;
use OCA\KanzleiCheck\Db\TaskMapper;
use OCA\KanzleiCheck\Service\ChatService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression: NC rejects IN lists with >1000 expressions (Oracle portability)
 * and acceptShared binds leave duplicate nc_uid rows. These sites must chunk
 * and must tolerate multi-row results.
 */
final class ChunkedInQueryTest extends TestCase
{
	/** @var list<array> int-array params captured per executeQuery call */
	private array $chunks = [];

	/**
	 * @param list<array{all?: list<array>, one?: mixed}> $queryResults
	 * one entry per expected executeQuery call
	 */
	private function db(array $queryResults): IDBConnection&MockObject
	{
		$this->chunks = [];
		$call = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($queryResults, &$call) {
			$qb = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('in')->willReturn('IN_EXPR');
			$expr->method('eq')->willReturn('EQ_EXPR');
			$expr->method('neq')->willReturn('NEQ_EXPR');
			$qb->method('expr')->willReturn($expr);
			$func = $this->createMock(IFunctionBuilder::class);
			$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
			$qb->method('func')->willReturn($func);
			$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
				if (is_array($value)) {
					$this->chunks[] = $value;
				}
				return ':p';
			});
			foreach (['select', 'from', 'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$result = $this->createMock(IResult::class);
			$set = $queryResults[$call] ?? [];
			$call++;
			$all = $set['all'] ?? [];
			$result->method('fetchAll')->willReturn($all);
			$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...array_merge($all, [false]));
			$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($all, [false]));
			$result->method('fetchOne')->willReturn($set['one'] ?? false);
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		return $db;
	}

	public function testCountsByReqChunksBeyond1000(): void
	{
		$mapper = new ReqItemMapper($this->db([
			['all' => [['req_id' => 1, 'state' => 'accepted', 'n' => 2], ['req_id' => 1, 'state' => 'open', 'n' => 1]]],
			['all' => [['req_id' => 1200, 'state' => 'open', 'n' => 3]]],
		]));
		$out = $mapper->countsByReq(range(1, 1500));
		$this->assertSame([1000, 500], array_map('count', $this->chunks));
		$this->assertSame(['total' => 3, 'accepted' => 2], $out[1]);
		$this->assertSame(['total' => 3, 'accepted' => 0], $out[1200]);
	}

	public function testCountOpenByOrgsSumsChunks(): void
	{
		$mapper = new TaskMapper($this->db([
			['one' => '7'],
			['one' => '4'],
			['one' => '1'],
		]));
		$this->assertSame(12, $mapper->countOpenByOrgs(range(1, 2500)));
		$this->assertSame([1000, 1000, 500], array_map('count', $this->chunks));
	}

	public function testInboxForStaffMergesSortedChunks(): void
	{
		$db = $this->db([
			['all' => [['id' => 5, 'org_id' => 50, 'state' => 'open', 'last_message_at' => '2026-01-02 10:00:00']]],
			['all' => [['id' => 3, 'org_id' => 1100, 'state' => 'open', 'last_message_at' => '2026-01-05 10:00:00']]],
		]);
		$messages = $this->createMock(MessageMapper::class);
		$messages->method('listPage')->willReturn([]);
		$messages->method('countAfter')->willReturn(0);
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->method('get')->willReturn(0);
		$svc = new ChatService($this->createMock(ConversationMapper::class), $messages, $reads, $db);
		$out = $svc->inboxForStaff('staff', range(1, 1100));
		$this->assertSame([1000, 100], array_map('count', $this->chunks));
		// merged then re-sorted by last_message_at DESC across chunk boundaries
		$this->assertSame([3, 5], array_column($out, 'convId'));
	}

	public function testFindAllByNcUidToleratesDuplicateRows(): void
	{
		$row = ['id' => 8, 'email' => 'a@x.de', 'display_name' => 'A', 'status' => 'active', 'nc_uid' => 'shared'];
		$mapper = new IdentityMapper($this->db([
			['all' => [$row, array_merge($row, ['id' => 9])]],
			['all' => [$row, array_merge($row, ['id' => 9])]],
		]));
		$all = $mapper->findAllByNcUid('shared');
		$this->assertCount(2, $all);
		$this->assertSame(8, $all[0]->getId());
		$this->assertSame(8, $mapper->findByNcUid('shared')?->getId());
	}
}

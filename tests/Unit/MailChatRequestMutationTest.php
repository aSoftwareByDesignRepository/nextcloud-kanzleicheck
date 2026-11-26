<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\ConversationMapper;
use OCA\KanzleiCheck\Db\DocRequest;
use OCA\KanzleiCheck\Db\DocRequestMapper;
use OCA\KanzleiCheck\Db\Message;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\ReadMarkerMapper;
use OCA\KanzleiCheck\Db\ReqFileMapper;
use OCA\KanzleiCheck\Db\ReqItemMapper;
use OCA\KanzleiCheck\Db\ReqRemindMapper;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\ChatService;
use OCA\KanzleiCheck\Service\RequestService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-hunter on three hot spots:
 *  - ChatService::inboxForStaff (chunking, re-sort, preview/unread fields)
 *  - RequestService overdue/dueReminders/parsePolicy date math
 *  - IntakeMailService MIME textLeaves/isAttachmentPart (via reflection —
 *    the public entry needs an ext-imap resource the test env lacks)
 */
class MailChatRequestMutationTest extends TestCase
{
	// ── RequestService: overdue + reminder policy ──

	private function requestService(): RequestService
	{
		return new RequestService(
			$this->createMock(DocRequestMapper::class),
			$this->createMock(ReqItemMapper::class),
			$this->createMock(ReqRemindMapper::class),
			$this->createMock(AuditService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(UploadMapper::class),
			$this->createMock(ReqFileMapper::class),
		);
	}

	private function req(?string $deadline, string $state = 'open', string $policy = '-3,0,+3x5'): DocRequest
	{
		$r = new DocRequest();
		$r->setDeadline($deadline === null ? null : new \DateTimeImmutable($deadline));
		$r->setState($state);
		$r->setReminderPolicy($policy);
		return $r;
	}

	public function testOverdueBranches(): void
	{
		$svc = $this->requestService();
		$yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
		$tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
		$this->assertFalse($svc->overdue($this->req(null)));                       // no deadline
		$this->assertFalse($svc->overdue($this->req($yesterday, 'answered')));     // closed state
		$this->assertFalse($svc->overdue($this->req($tomorrow)));                  // future
		$this->assertTrue($svc->overdue($this->req($yesterday)));                  // open + past
	}

	public function testParsePolicyMatrix(): void
	{
		// default shape "-3,0,+3x5"
		$this->assertSame([[-3, 0], 3, 5], RequestService::parsePolicy('-3,0,+3x5'));
		// clamping: step ≤30, count ≤ MAX_REMINDERS, both ≥1
		$this->assertSame([[], 30, 5], RequestService::parsePolicy('+99x99'));
		$this->assertSame([[], 1, 1], RequestService::parsePolicy('+0x0'));
		// garbage parts ignored
		$this->assertSame([[7], 3, 5], RequestService::parsePolicy('foo,7,bar'));
	}

	public function testDueRemindersSchedule(): void
	{
		$svc = $this->requestService();
		// no deadline → empty, regardless of today
		$this->assertSame([], $svc->dueReminders($this->req(null), new \DateTimeImmutable('2026-06-15')));
		// policy -3,0,+3x5, deadline 2026-06-10 → offsets -3=06-07, 0=06-10,
		// extras +3=06-13,+6=06-16,+9=06-19 (5 total)
		$r = $this->req('2026-06-10');
		$this->assertSame(
			['2026-06-07', '2026-06-10', '2026-06-13'],
			$svc->dueReminders($r, new \DateTimeImmutable('2026-06-13')) // today boundary: ≤ today
		);
		$this->assertSame(
			['2026-06-07', '2026-06-10', '2026-06-13', '2026-06-16', '2026-06-19'],
			$svc->dueReminders($r, new \DateTimeImmutable('2026-06-30'))
		);
	}

	// ── ChatService::inboxForStaff ──

	private function chatQb(array $rows): IDBConnection&MockObject
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('IN');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		foreach (['select', 'from', 'where', 'orderBy'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($res);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function msg(int $id, string $body, string $author): Message
	{
		$m = new Message();
		$m->setId($id);
		$m->setBody($body);
		$m->setAuthor($author);
		return $m;
	}

	public function testInboxForStaffEmptyOrgs(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$svc = new ChatService(
			$this->createMock(ConversationMapper::class),
			$this->createMock(MessageMapper::class),
			$this->createMock(ReadMarkerMapper::class),
			$db,
		);
		$this->assertSame([], $svc->inboxForStaff('staffer', []));
	}

	public function testInboxForStaffSortsAndMaps(): void
	{
		$rows = [
			['id' => 2, 'org_id' => 5, 'state' => 'open', 'last_message_at' => '2026-06-02 10:00:00'],
			['id' => 1, 'org_id' => 5, 'state' => 'open', 'last_message_at' => '2026-06-09 10:00:00'],
		];
		$db = $this->chatQb($rows);
		$messages = $this->createMock(MessageMapper::class);
		$messages->method('listPage')->willReturnMap([
			[1, 1, [$this->msg(9, str_repeat('x', 130), 'client')]],
			[2, 1, []],
		]);
		$messages->method('countAfter')->willReturnMap([[1, 0, 'me', 4], [2, 0, 'me', 0]]);
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->method('get')->willReturn(0);
		$svc = new ChatService($this->createMock(ConversationMapper::class), $messages, $reads, $db);
		$out = $svc->inboxForStaff('me', [5]);
		$this->assertCount(2, $out);
		// re-sorted by last_message_at DESC — conv 1 first despite row order
		$this->assertSame(1, $out[0]['convId']);
		$this->assertSame(5, $out[0]['orgId']);
		$this->assertSame(120, mb_strlen($out[0]['lastPreview'])); // truncated at 120
		$this->assertSame('client', $out[0]['lastAuthor']);
		$this->assertSame(4, $out[0]['unread']);
		$this->assertNull($out[1]['lastPreview']); // no messages → null fields
		$this->assertNull($out[1]['lastAuthor']);
	}

	// ── IntakeMailService MIME traversal ──

	private function callPrivate(object $svc, string $method, array $args): mixed
	{
		$m = new \ReflectionMethod($svc, $method);
		return $m->invoke($svc, ...$args);
	}

	private function part(int $type, string $subtype = 'PLAIN', array $extra = []): object
	{
		return (object)array_merge(['type' => $type, 'subtype' => $subtype], $extra);
	}

	public function testTextLeavesTraversal(): void
	{
		$svc = new \ReflectionClass(\OCA\KanzleiCheck\Service\IntakeMailService::class);
		$inst = $svc->newInstanceWithoutConstructor();

		// root leaf (non-multipart, plain text) → partNo ''
		$leaves = $this->callPrivate($inst, 'textLeaves', [$this->part(0), '']);
		$this->assertSame([['', $leaves[0][1]]], $leaves);

		// attachment by disposition is skipped
		$att = $this->part(0, 'PLAIN', ['ifdisposition' => 1, 'disposition' => 'ATTACHMENT']);
		$this->assertSame([], $this->callPrivate($inst, 'textLeaves', [$att, '']));

		// attachment by filename parameter is skipped
		$att2 = $this->part(0, 'PLAIN', [
			'dparameters' => [(object)['attribute' => 'FILENAME', 'value' => 'doc.pdf']],
		]);
		$this->assertSame([], $this->callPrivate($inst, 'textLeaves', [$att2, '']));

		// multipart/mixed: numbering 1,2 — nested multipart recursion 2.1
		$root = (object)['type' => 1, 'parts' => [
			$this->part(0, 'PLAIN'),                                          // → 1
			(object)['type' => 1, 'parts' => [$this->part(0, 'HTML')]],        // → 2.1
			$this->part(2, 'OCTET-STREAM'),                                   // binary → skipped
		]];
		$leaves = $this->callPrivate($inst, 'textLeaves', [$root, '']);
		$this->assertSame(['1', '2.1'], array_column($leaves, 0));
	}

	public function testIsAttachmentPartLegs(): void
	{
		$svc = new \ReflectionClass(\OCA\KanzleiCheck\Service\IntakeMailService::class);
		$inst = $svc->newInstanceWithoutConstructor();

		// ifdisposition=0 + attachment disposition → not an attachment
		$this->assertFalse($this->callPrivate($inst, 'isAttachmentPart',
			[$this->part(0, 'PLAIN', ['ifdisposition' => 0, 'disposition' => 'attachment'])]));
		// empty filename value → not an attachment
		$this->assertFalse($this->callPrivate($inst, 'isAttachmentPart',
			[$this->part(0, 'PLAIN', ['parameters' => [(object)['attribute' => 'name', 'value' => '  ']]])]));
		// plain part → false
		$this->assertFalse($this->callPrivate($inst, 'isAttachmentPart', [$this->part(0)]));
	}
}

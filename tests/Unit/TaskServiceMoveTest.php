<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Task;
use OCA\KanzleiCheck\Db\TaskMapper;
use OCA\KanzleiCheck\Exception\ConflictException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Board drag-drop writes through TaskService::move — a status change via
 * revision CAS inside a transaction. These tests pin the contract: invalid
 * statuses are rejected before any DB touch, stale revisions conflict
 * (never silent last-writer-wins), and done_at follows the done status.
 */
final class TaskServiceMoveTest extends TestCase
{
	private TaskMapper $tasks;
	private AuditService $audit;
	private IDBConnection $db;
	private TaskService $svc;

	protected function setUp(): void
	{
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->svc = new TaskService($this->tasks, $this->audit, $this->db);
	}

	private static function task(int $rev = 1, string $status = 'new'): Task
	{
		$t = new Task();
		$t->setId(5);
		$t->setOrgId(9);
		$t->setTitle('Vollmacht');
		$t->setStatus($status);
		$t->setPriority('normal');
		$t->setPortalVisibility('status');
		$t->setRevision($rev);
		$t->setCreatedBy('staffer');
		$t->setCreatedAt(new \DateTimeImmutable());
		$t->setUpdatedAt(new \DateTimeImmutable());
		return $t;
	}

	public function testInvalidStatusRejectedBeforeAnyDbTouch(): void
	{
		$this->tasks->expects($this->never())->method('findForUpdate');
		$this->db->expects($this->never())->method('beginTransaction');
		try {
			$this->svc->move('staffer', 5, 'bogus', 1);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			// the fields map drives inline .kzc-field-error on the named control
			$this->assertSame('invalid', $e->getFields()['status'] ?? null);
		}
	}

	public function testMoveAppliesStatusBumpsRevisionAndSetsDoneAt(): void
	{
		$t = self::task(1, 'new');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->tasks->method('findForUpdate')->with(5)->willReturn($t);
		$this->audit->expects($this->once())->method('log')
			->with('staffer', 'task.status', 'task', 5, $this->callback(
				fn($d) => ($d['from'] ?? '') === 'new' && ($d['to'] ?? '') === 'done'));
		$this->tasks->expects($this->once())->method('update')->with($this->isInstanceOf(Task::class));

		$out = $this->svc->move('staffer', 5, 'done', 1);
		$this->assertSame('done', $out->getStatus());
		$this->assertSame(2, $out->getRevision());
		$this->assertInstanceOf(\DateTimeImmutable::class, $out->getDoneAt());
	}

	public function testMoveOutOfDoneClearsDoneAt(): void
	{
		$t = self::task(3, 'done');
		$t->setDoneAt(new \DateTimeImmutable('2026-01-01 10:00:00'));
		$this->tasks->method('findForUpdate')->willReturn($t);
		$this->tasks->method('update')->willReturnArgument(0);

		$out = $this->svc->move('staffer', 5, 'waiting', 3);
		$this->assertSame('waiting', $out->getStatus());
		$this->assertNull($out->getDoneAt());
		$this->assertSame(4, $out->getRevision());
	}

	public function testStaleRevisionConflictsAndRollsBack(): void
	{
		$this->tasks->method('findForUpdate')->willReturn(self::task(2, 'new'));
		$this->tasks->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(ConflictException::class);
		$this->svc->move('staffer', 5, 'done', 1);
	}

	public function testNewToWaitingDoesNotSetDoneAt(): void
	{
		// a card leaving 'new' for a non-done column must never gain done_at
		// (kills the done_at-condition mutants in applyStatus)
		$t = self::task(1, 'new');
		$this->tasks->method('findForUpdate')->willReturn($t);
		$this->tasks->method('update')->willReturnArgument(0);
		$out = $this->svc->move('staffer', 5, 'waiting', 1);
		$this->assertNull($out->getDoneAt());
	}

	public function testUpdateIgnoresInvalidStatusPriorityAndVisibility(): void
	{
		// updateTask forwards arbitrary body fields — invalid enum values must
		// be ignored, not applied (a negated in_array would silently write them)
		$t = self::task(1, 'new');
		$this->tasks->method('findForUpdate')->willReturn($t);
		$this->tasks->method('update')->willReturnArgument(0);
		$this->audit->expects($this->never())->method('log');
		$out = $this->svc->update('staffer', 5, ['status' => 'bogus', 'priority' => 'bogus', 'portalVisibility' => 'bogus'], 1);
		$this->assertSame('new', $out->getStatus());
		$this->assertSame('normal', $out->getPriority());
		$this->assertSame('status', $out->getPortalVisibility());
		$this->assertNull($out->getDoneAt());
		$this->assertSame(2, $out->getRevision());
	}

	public function testUpdateBumpsUpdatedAt(): void
	{
		$t = self::task(1, 'new');
		$t->setUpdatedAt(new \DateTimeImmutable('2020-01-01 00:00:00'));
		$this->tasks->method('findForUpdate')->willReturn($t);
		$this->tasks->method('update')->willReturnArgument(0);
		$out = $this->svc->move('staffer', 5, 'waiting', 1);
		$this->assertGreaterThan(new \DateTimeImmutable('2020-01-01 00:00:00'), $out->getUpdatedAt());
	}

	public function testMissingTaskNotFoundAndRollsBack(): void
	{
		$this->tasks->method('findForUpdate')->willReturn(null);
		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(NotFoundException::class);
		$this->svc->move('staffer', 5, 'done', 1);
	}
}

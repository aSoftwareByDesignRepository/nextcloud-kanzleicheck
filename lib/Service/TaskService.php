<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\Task;
use OCA\KanzleiCheck\Db\TaskMapper;
use OCA\KanzleiCheck\Exception\ConflictException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Tasks + kanban (US-P13/P14). Every write carries the task's revision —
 * a stale revision → CONFLICT (fail-closed CAS, never silent last-writer-
 * wins, api-security §3). Status changes are audited; done sets done_at,
 * leaving done clears it.
 */
class TaskService
{
	public const STATUSES = ['new', 'in_progress', 'waiting', 'done'];
	public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
	public const VISIBILITIES = ['none', 'status'];

	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
	) {
	}

	public function mustGet(int $id): Task
	{
		$t = $this->tasks->find($id);
		if ($t === null) {
			throw new NotFoundException();
		}
		return $t;
	}

	public function create(string $actor, int $orgId, array $fields): Task
	{
		$title = trim((string)($fields['title'] ?? ''));
		if ($title === '' || mb_strlen($title) > 190) {
			throw new ValidationException('VALIDATION_FAILED', ['title' => 'required']);
		}
		$status = in_array($fields['status'] ?? '', self::STATUSES, true) ? $fields['status'] : 'new';
		$priority = in_array($fields['priority'] ?? '', self::PRIORITIES, true) ? $fields['priority'] : 'normal';
		$vis = in_array($fields['portalVisibility'] ?? '', self::VISIBILITIES, true) ? $fields['portalVisibility'] : 'status';
		$note = isset($fields['note']) ? mb_substr((string)$fields['note'], 0, 5000) : null;
		$due = $this->parseDate($fields['dueDate'] ?? null);
		$now = new \DateTimeImmutable();
		$t = new Task();
		$t->setOrgId($orgId);
		$t->setTitle($title);
		$t->setNote($note);
		$t->setStatus($status);
		$t->setPriority($priority);
		$t->setDueDate($due);
		$t->setPortalVisibility($vis);
		$t->setRevision(1);
		$t->setCreatedBy($actor);
		$t->setAssignee(isset($fields['assignee']) ? mb_substr((string)$fields['assignee'], 0, 64) : null);
		$t->setCreatedAt($now);
		$t->setUpdatedAt($now);
		if ($status === 'done') {
			$t->setDoneAt($now);
		}
		$t = $this->tasks->insert($t);
		$this->audit->log($actor, 'task.create', 'task', (int)$t->getId());
		return $t;
	}

	/** CAS write — expected revision must match or ConflictException. */
	public function update(string $actor, int $id, array $fields, int $expectedRevision): Task
	{
		$this->db->beginTransaction();
		try {
			$t = $this->tasks->findForUpdate($id);
			if ($t === null) {
				throw new NotFoundException();
			}
			if ($t->getRevision() !== $expectedRevision) {
				throw new ConflictException('CONFLICT');
			}
			if (isset($fields['title'])) {
				$title = trim((string)$fields['title']);
				if ($title === '' || mb_strlen($title) > 190) {
					throw new ValidationException('VALIDATION_FAILED', ['title' => 'required']);
				}
				$t->setTitle($title);
			}
			if (array_key_exists('note', $fields)) {
				$t->setNote($fields['note'] === null ? null : mb_substr((string)$fields['note'], 0, 5000));
			}
			if (isset($fields['priority']) && in_array($fields['priority'], self::PRIORITIES, true)) {
				$t->setPriority($fields['priority']);
			}
			if (array_key_exists('dueDate', $fields)) {
				$t->setDueDate($this->parseDate($fields['dueDate']));
			}
			if (isset($fields['assignee'])) {
				$t->setAssignee($fields['assignee'] === null || $fields['assignee'] === '' ? null : mb_substr((string)$fields['assignee'], 0, 64));
			}
			if (isset($fields['portalVisibility']) && in_array($fields['portalVisibility'], self::VISIBILITIES, true)) {
				if ($fields['portalVisibility'] !== $t->getPortalVisibility()) {
					$this->audit->log($actor, 'task.visibility', 'task', $id, ['from' => $t->getPortalVisibility(), 'to' => $fields['portalVisibility']]);
				}
				$t->setPortalVisibility($fields['portalVisibility']);
			}
			if (isset($fields['status']) && in_array($fields['status'], self::STATUSES, true) && $fields['status'] !== $t->getStatus()) {
				$from = $t->getStatus();
				$this->applyStatus($t, $fields['status']);
				$this->audit->log($actor, 'task.status', 'task', $id, ['from' => $from, 'to' => $fields['status']]);
			}
			$t->setRevision($expectedRevision + 1);
			$t->setUpdatedAt(new \DateTimeImmutable());
			$this->tasks->update($t);
			$this->db->commit();
			return $t;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Board move = status change via CAS. */
	public function move(string $actor, int $id, string $status, int $expectedRevision): Task
	{
		if (!in_array($status, self::STATUSES, true)) {
			throw new ValidationException('VALIDATION_FAILED', ['status' => 'invalid']);
		}
		return $this->update($actor, $id, ['status' => $status], $expectedRevision);
	}

	private function applyStatus(Task $t, string $status): void
	{
		$wasDone = $t->getStatus() === 'done';
		$t->setStatus($status);
		if ($status === 'done' && !$wasDone) {
			$t->setDoneAt(new \DateTimeImmutable());
		} elseif ($status !== 'done' && $wasDone) {
			$t->setDoneAt(null);
		}
	}

	private function parseDate(mixed $raw): ?\DateTimeImmutable
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$s = (string)$raw;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
			return null;
		}
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
		return $d !== false ? $d : null;
	}

	public function delete(string $actor, int $id): void
	{
		$t = $this->mustGet($id);
		$this->tasks->delete($t);
		$this->audit->log($actor, 'task.delete', 'task', $id);
	}
}

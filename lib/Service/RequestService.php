<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\DocRequest;
use OCA\KanzleiCheck\Db\DocRequestMapper;
use OCA\KanzleiCheck\Db\ReqFileMapper;
use OCA\KanzleiCheck\Db\ReqItem;
use OCA\KanzleiCheck\Db\ReqItemMapper;
use OCA\KanzleiCheck\Db\ReqRemindMapper;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Document requests (US-P21) — checklist items with deadlines.
 * State machine (§9.3):
 *   request: open → complete (all items accepted) | closed (staff, terminal)
 *   item:    open → delivered → accepted | rejected → delivered again
 * Reminders stop on complete/closed or when all items are delivered+.
 *
 * unique-conflict:ok the kzc_req_item write sites (deliver/confirm/review/
 * remind transitions) update state/reviewer columns only — kzc_req_item
 * carries no unique index. The sole unique-index write (kzc_req insert on
 * kzc_req_period_uq) sits inside the transaction above with a REASON_UNIQUE
 * catch mapped to a periodKey field error.
 */
class RequestService
{
	public const MAX_ITEMS = 50;
	public const MAX_OPEN_PER_ORG = 200;
	public const MAX_REMINDERS = 5;
	public const ITEM_KINDS = ['upload', 'confirm', 'form'];

	public function __construct(
		private readonly DocRequestMapper $requests,
		private readonly ReqItemMapper $items,
		private readonly ReqRemindMapper $reminds,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
		private readonly UploadMapper $uploads,
		private readonly ReqFileMapper $reqFiles,
	) {
	}

	public function mustGet(int $id): DocRequest
	{
		$r = $this->requests->find($id);
		if ($r === null) {
			throw new NotFoundException();
		}
		return $r;
	}

	public function mustGetInOrg(int $id, int $orgId): DocRequest
	{
		$r = $this->mustGet($id);
		if ((int)$r->getOrgId() !== $orgId) {
			throw new NotFoundException(); // G-01 uniform
		}
		return $r;
	}

	public function mustGetItemInReq(int $reqId, int $itemId): ReqItem
	{
		$i = $this->items->find($itemId);
		if ($i === null || (int)$i->getReqId() !== $reqId) {
			throw new NotFoundException();
		}
		return $i;
	}

	/**
	 * Create request + items in ONE transaction (ZS-04) — a partial request
	 * can never exist. $items: list of {label, hint?, kind, formId?}.
	 */
	public function create(string $actor, int $orgId, array $data): DocRequest
	{
		$title = trim(self::strictStr($data['title'] ?? '', 'title'));
		if ($title === '' || mb_strlen($title) > 190) {
			throw new ValidationException('VALIDATION_FAILED', ['title' => 'required']);
		}
		$note = isset($data['note']) ? mb_substr(self::strictStr($data['note'], 'note'), 0, 2000) : null;
		$items = $data['items'] ?? [];
		if (!is_array($items) || count($items) < 1 || count($items) > self::MAX_ITEMS) {
			throw new ValidationException('VALIDATION_FAILED', ['items' => 'count']);
		}
		$deadline = $this->parseDate($data['deadline'] ?? null, true);
		$policy = trim(self::strictStr($data['reminderPolicy'] ?? '-3,0,+3x5', 'reminderPolicy')) ?: '-3,0,+3x5';
		$this->db->beginTransaction();
		try {
			// Cap race (§9.9 pattern): FOR UPDATE on the org row serializes
			// creates — a count outside the txn overshoots under concurrency.
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('kzc_org')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($orgId)))
				->forUpdate();
			if ($qb->executeQuery()->fetchOne() === false) {
				throw new ApiException(404, 'NOT_FOUND', 'org gone');
			}
			if ($this->requests->countOpenByOrg($orgId) >= self::MAX_OPEN_PER_ORG) {
				throw new ApiException(409, 'VALIDATION_FAILED', 'too many open requests');
			}
			$r = new DocRequest();
			$r->setOrgId($orgId);
			$r->setTitle($title);
			$r->setNote($note);
			$r->setDeadline($deadline);
			$r->setState('open');
			$r->setTaskId(isset($data['taskId']) ? self::strictInt($data['taskId'], 'taskId') : null);
			$r->setTemplateId(isset($data['templateId']) ? self::strictInt($data['templateId'], 'templateId') : null);
			$r->setPeriodKey(isset($data['periodKey']) ? self::strictStr($data['periodKey'], 'periodKey') : null);
			$r->setReminderPolicy(mb_substr($policy, 0, 64));
			$r->setRemindersSent(0);
			$r->setCreatedBy($actor);
			$r->setCreatedAt(new \DateTimeImmutable());
			$r = $this->requests->insert($r);
			$pos = 0;
			foreach ($items as $it) {
				if (!is_array($it)) {
					throw new ValidationException('VALIDATION_FAILED', ['items' => 'invalid']);
				}
				$label = trim(self::strictStr($it['label'] ?? '', 'items.label'));
				if ($label === '' || mb_strlen($label) > 190) {
					throw new ValidationException('VALIDATION_FAILED', ['items' => 'label']);
				}
				$kind = in_array($it['kind'] ?? '', self::ITEM_KINDS, true) ? $it['kind'] : 'upload';
				$i = new ReqItem();
				$i->setReqId((int)$r->getId());
				$i->setPosition($pos++);
				$i->setLabel($label);
				$i->setHint(isset($it['hint']) ? mb_substr(self::strictStr($it['hint'], 'items.hint'), 0, 500) : null);
				$i->setKind($kind);
				$i->setFormId($kind === 'form' && isset($it['formId']) ? self::strictInt($it['formId'], 'items.formId') : null);
				$i->setState('open');
				$this->items->insert($i);
			}
			$this->db->commit();
			$this->audit->log($actor, 'request.create', 'request', (int)$r->getId());
			return $r;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// kzc_req_period_uq race: two concurrent creates for the same
			// template+org+period — the loser gets the field error, not a 500
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new ValidationException('VALIDATION_FAILED', ['periodKey' => 'duplicate']);
			}
			throw $e;
		}
	}

	/** Client delivers an upload item (or re-delivers a rejected one). */
	public function deliverItem(string $actor, int $reqId, int $itemId, int $uploadId = 0): ReqItem
	{
		$r = $this->mustGet($reqId);
		if ($r->getState() !== 'open') {
			throw new ApiException(409, 'REQUEST_CLOSED', 'request closed');
		}
		$i = $this->mustGetItemInReq($reqId, $itemId);
		if ($i->getState() === 'accepted') {
			throw new ApiException(409, 'REQUEST_CLOSED', 'item already accepted');
		}
		if ($uploadId > 0) {
			$u = $this->uploads->find($uploadId);
			// uniform 404 (G-01): unknown OR foreign-org upload ids collapse —
			// never bind another org's file into this request's item.
			if ($u === null || (int)$u->getOrgId() !== (int)$r->getOrgId()) {
				throw new NotFoundException();
			}
		}
		$i->setState('delivered');
		$i->setRejectReason(null);
		$i->setDeliveredBy($actor);
		$i->setDeliveredAt(new \DateTimeImmutable());
		$this->items->update($i);
		if ($uploadId > 0) {
			$this->reqFiles->attach($itemId, $uploadId);
		}
		return $i;
	}

	/** Client ticks a confirm item. */
	public function confirmItem(string $actor, int $reqId, int $itemId): ReqItem
	{
		$r = $this->mustGet($reqId);
		if ($r->getState() !== 'open') {
			throw new ApiException(409, 'REQUEST_CLOSED', 'request closed');
		}
		$i = $this->mustGetItemInReq($reqId, $itemId);
		if ($i->getKind() !== 'confirm') {
			throw new NotFoundException();
		}
		$i->setState('delivered');
		$i->setDeliveredBy($actor);
		$i->setDeliveredAt(new \DateTimeImmutable());
		$this->items->update($i);
		return $i;
	}

	/** Staff accept/reject (rejected re-opens with reason ≤500). */
	public function reviewItem(string $actor, int $reqId, int $itemId, bool $accept, ?string $reason): ReqItem
	{
		$r = $this->mustGet($reqId);
		if ($r->getState() !== 'open') {
			throw new ApiException(409, 'REQUEST_CLOSED', 'request closed');
		}
		$i = $this->mustGetItemInReq($reqId, $itemId);
		if ($i->getState() !== 'delivered') {
			throw new ApiException(409, 'VALIDATION_FAILED', 'item not delivered');
		}
		if ($accept) {
			$i->setState('accepted');
			$i->setRejectReason(null);
		} else {
			$reason = trim((string)$reason);
			if ($reason === '' || mb_strlen($reason) > 500) {
				throw new ValidationException('VALIDATION_FAILED', ['reason' => 'required']);
			}
			$i->setState('rejected');
			$i->setRejectReason($reason);
		}
		$i->setReviewedBy($actor);
		$i->setReviewedAt(new \DateTimeImmutable());
		$this->items->update($i);
		// auto-complete: all items accepted → request complete
		if ($accept) {
			$this->completeIfDone($reqId);
		}
		return $i;
	}

	public function completeIfDone(int $reqId): void
	{
		$r = $this->mustGet($reqId);
		if ($r->getState() !== 'open') {
			return;
		}
		foreach ($this->items->findByReq($reqId) as $i) {
			if ($i->getState() !== 'accepted') {
				return;
			}
		}
		$this->closeRequest($reqId, 'complete');
	}

	public function closeRequest(int $reqId, string $state = 'closed', string $actor = ''): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('kzc_req')
			->set('state', $qb->createNamedParameter($state === 'complete' ? 'complete' : 'closed'))
			->set('closed_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($reqId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('open')));
		if ($qb->executeStatement() === 1 && $actor !== '') {
			$this->audit->log($actor, 'request.' . ($state === 'complete' ? 'complete' : 'close'), 'request', $reqId);
		}
	}

	public function overdue(DocRequest $r): bool
	{
		$d = $r->getDeadline();
		return $d !== null && $r->getState() === 'open' && $d < new \DateTimeImmutable('today');
	}

	/**
	 * Reminder due dates for a request (policy "-3,0,+3x5": 3 days before,
	 * on deadline, then every 3 days, ≤5 total). Returns YMD list ≤ today.
	 * @return list<string>
	 */
	public function dueReminders(DocRequest $r, \DateTimeImmutable $today): array
	{
		if ($r->getDeadline() === null) {
			return [];
		}
		[$offsets, $repeatStep, $maxN] = self::parsePolicy((string)$r->getReminderPolicy());
		$dates = [];
		foreach ($offsets as $o) {
			$dates[] = $r->getDeadline()->modify(($o >= 0 ? '+' : '') . $o . ' days')->format('Y-m-d');
		}
		$extra = count($dates);
		for ($n = $extra; $n < $maxN; $n++) {
			$dates[] = $r->getDeadline()->modify('+' . ($repeatStep * ($n - $extra + 1)) . ' days')->format('Y-m-d');
		}
		return array_values(array_filter($dates, static fn($d) => $d <= $today->format('Y-m-d')));
	}

	/** Parse "-3,0,+3x5" → [[-3,0], 3, 5]. */
	public static function parsePolicy(string $policy): array
	{
		$offsets = [];
		$step = 3;
		$max = self::MAX_REMINDERS;
		foreach (explode(',', $policy) as $part) {
			$part = trim($part);
			if (preg_match('/^([+-]?\d+)x(\d+)$/', $part, $m)) {
				$step = max(1, min(30, (int)$m[1]));
				$max = max(1, min(self::MAX_REMINDERS, (int)$m[2]));
			} elseif (preg_match('/^[+-]?\d+$/', $part)) {
				$offsets[] = (int)$part;
			}
		}
		return [$offsets, $step, $max];
	}

	/** Request-boundary scalar (api-security §2): string only — "Array" never lands. */
	private static function strictStr(mixed $v, string $field): string
	{
		if (!is_string($v)) {
			throw new ValidationException('VALIDATION_FAILED', [$field => 'invalid']);
		}
		return $v;
	}

	/** Request-boundary scalar (api-security §2): int or digit-string only. */
	private static function strictInt(mixed $v, string $field): int
	{
		if (is_int($v)) {
			return $v;
		}
		if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
			return (int)$v;
		}
		throw new ValidationException('VALIDATION_FAILED', [$field => 'invalid']);
	}

	private function parseDate(mixed $raw, bool $todayOrLater): ?\DateTimeImmutable
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$raw);
		if ($d === false) {
			throw new ValidationException('VALIDATION_FAILED', ['deadline' => 'invalid']);
		}
		if ($todayOrLater && $d < new \DateTimeImmutable('today')) {
			throw new ValidationException('VALIDATION_FAILED', ['deadline' => 'past']);
		}
		return $d;
	}
}

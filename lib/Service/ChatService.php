<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\Conversation;
use OCA\KanzleiCheck\Db\ConversationMapper;
use OCA\KanzleiCheck\Db\Message;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\ReadMarkerMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * One conversation per org (US-P09/P10). client_uuid + UNIQUE dedupe makes
 * retries exactly-once. Read markers are per-actor, monotonic. Org archived
 * → conversation read-only for staff, invisible to clients.
 */
class ChatService
{
	public const MAX_BODY = 4000;
	public const RATE_LIMIT = 30; // messages/min per identity

	public function __construct(
		private readonly ConversationMapper $convs,
		private readonly MessageMapper $messages,
		private readonly ReadMarkerMapper $reads,
		private readonly IDBConnection $db,
	) {
	}

	public function convForOrg(int $orgId, bool $create = true): ?Conversation
	{
		$c = $this->convs->findByOrg($orgId);
		if ($c === null && $create) {
			$c = new Conversation();
			$c->setOrgId($orgId);
			$c->setState('open');
			$c->setCreatedAt(new \DateTimeImmutable());
			try {
				$c = $this->convs->insert($c);
			} catch (DbException $e) {
				// kzc_conv_org_uq race: concurrent first message in an org — read the winner
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$c = $this->convs->findByOrg($orgId);
			}
		}
		return $c;
	}

	/**
	 * Post a message. $clientUuid is the idempotency key — a retry with the
	 * same uuid returns the existing row (201, same id, never a duplicate).
	 */
	public function post(int $orgId, string $actor, string $authorKind, string $body, ?string $clientUuid, ?int $uploadId = null): Message
	{
		$body = trim($body);
		if ($body === '' && $uploadId === null) {
			throw new ValidationException('VALIDATION_FAILED', ['body' => 'required']);
		}
		if (mb_strlen($body) > self::MAX_BODY) {
			throw new ValidationException('VALIDATION_FAILED', ['body' => 'too_long']);
		}
		if ($clientUuid !== null && $clientUuid !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $clientUuid)) {
			throw new ValidationException('VALIDATION_FAILED', ['clientUuid' => 'invalid']);
		}
		$conv = $this->convForOrg($orgId);
		if ($conv === null) {
			throw new NotFoundException();
		}
		if ($conv->getState() !== 'open') {
			throw new ApiException(409, 'ORG_ARCHIVED', 'conversation archived');
		}
		if ($authorKind === 'client') {
			$since = (new \DateTimeImmutable())->modify('-1 minute');
			if ($this->messages->countRecentByAuthor((int)$conv->getId(), $actor, $since) >= self::RATE_LIMIT) {
				throw new ApiException(429, 'RATE_LIMITED', 'too many messages', [], 30);
			}
		}
		$convId = (int)$conv->getId();
		if ($clientUuid !== null && $clientUuid !== '') {
			$existing = $this->messages->findByDedupe($convId, $actor, $clientUuid);
			if ($existing !== null) {
				return $existing;
			}
		}
		$this->db->beginTransaction();
		try {
			$m = new Message();
			$m->setConvId($convId);
			$m->setAuthor($actor);
			$m->setAuthorKind(in_array($authorKind, ['client', 'staff', 'system'], true) ? $authorKind : 'client');
			$m->setClientUuid($clientUuid !== '' ? $clientUuid : null);
			$m->setBody($body !== '' ? $body : null);
			$m->setUploadId($uploadId);
			$m->setCreatedAt(new \DateTimeImmutable());
			$m = $this->messages->insert($m);
			$qb = $this->db->getQueryBuilder();
			$qb->update('kzc_conv')->set('last_message_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($convId)));
			$qb->executeStatement();
			$this->db->commit();
			return $m;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// dedupe race: second insert of same client_uuid → return the
			// winner row (its transaction already committed — replay is safe)
			if ($e instanceof DbException
				&& $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& $clientUuid !== null && $clientUuid !== '') {
				$existing = $this->messages->findByDedupe($convId, $actor, $clientUuid);
				if ($existing !== null) {
					return $existing;
				}
			}
			throw $e;
		}
	}

	public function markRead(int $convId, string $actor, int $lastReadId): void
	{
		if ($lastReadId > 0) {
			$this->reads->advance($convId, $actor, $lastReadId);
		}
	}

	public function unreadCount(int $convId, string $actor): int
	{
		return $this->messages->countAfter($convId, $this->reads->get($convId, $actor), $actor);
	}

	/** Staff inbox: conversations with last activity + unread for this actor. */
	public function inboxForStaff(string $actor, array $orgIds): array
	{
		if ($orgIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.org_id', 'c.state', 'c.last_message_at')
			->from('kzc_conv', 'c')
			->where($qb->expr()->in('c.org_id', $qb->createNamedParameter(array_map('intval', $orgIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('c.last_message_at', 'DESC');
		$rows = $qb->executeQuery()->fetchAll() ?: [];
		$out = [];
		foreach ($rows as $r) {
			$convId = (int)$r['id'];
			$last = $this->messages->listPage($convId, 1);
			$out[] = [
				'convId' => $convId,
				'orgId' => (int)$r['org_id'],
				'state' => $r['state'],
				'lastMessageAt' => $r['last_message_at'],
				'lastPreview' => $last !== [] ? mb_substr((string)($last[0]->getBody() ?? ''), 0, 120) : null,
				'lastAuthor' => $last !== [] ? $last[0]->getAuthor() : null,
				'unread' => $this->unreadCount($convId, $actor),
			];
		}
		return $out;
	}

	public function archiveForOrg(int $orgId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('kzc_conv')->set('state', $qb->createNamedParameter('archived'))
			->where($qb->expr()->eq('org_id', $qb->createNamedParameter($orgId)));
		$qb->executeStatement();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\IntakeHoldMapper;
use OCA\KanzleiCheck\Db\OrgMapper;

/**
 * Inbound mail intake (US-P24). The IMAP fetch is deliberately thin and
 * feature-detected: environments without ext-imap still run every other
 * surface — diagnostics surfaces the gap instead of silently skipping
 * (NN-P24-style fail-visible). Message parsing/dedupe/sender policy is
 * transport-independent and unit-testable.
 */
class IntakeMailService
{
	public function __construct(
		private readonly IntakeService $intake,
		private readonly IntakeHoldMapper $holds,
		private readonly OrgMapper $orgs,
		private readonly SettingsService $settings,
		private readonly UploadService $uploads,
		private readonly IntakeHoldService $holdService,
		private readonly ChatService $chat,
		private readonly DocumentService $docs,
		private readonly \OCP\IDBConnection $db,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	public function imapAvailable(): bool
	{
		return function_exists('imap_open');
	}

	/**
	 * One bounded poll pass (called by SchedulerJob when enabled).
	 * No-ops when unconfigured or ext-imap missing.
	 * @return array{polled:int,stored:int,held:int,skipped:int,error:?string}
	 */
	public function poll(int $maxMessages = 50, int $maxAttachmentBytes = 25 * 1024 * 1024): array
	{
		$stats = ['polled' => 0, 'stored' => 0, 'held' => 0, 'skipped' => 0, 'error' => null];
		if (!$this->settings->getBool('inbound_enabled')) {
			return $stats;
		}
		if (!$this->imapAvailable()) {
			$stats['error'] = 'NO_IMAP_EXTENSION';
			return $stats;
		}
		$host = $this->settings->get('inbound_host');
		$user = $this->settings->get('inbound_user');
		$pass = $this->settings->getSecret('inbound_pass_enc');
		if ($host === '' || $user === '' || $pass === '') {
			$stats['error'] = 'NOT_CONFIGURED';
			return $stats;
		}
		$port = $this->settings->getInt('inbound_port', 993);
		$mailbox = sprintf('{%s:%d/imap/ssl/novalidate-cert}INBOX', $host, $port);
		$conn = @imap_open($mailbox, $user, $pass, 0, 1);
		if ($conn === false) {
			$stats['error'] = 'CONNECT_FAILED';
			$this->logger->warning('kanzleicheck intake IMAP connect failed', ['host' => $host]);
			return $stats;
		}
		try {
			$uids = imap_search($conn, 'UNSEEN', SE_UID) ?: [];
			$uids = array_slice($uids, 0, $maxMessages);
			foreach ($uids as $uid) {
				$stats['polled']++;
				try {
					$this->processMessage($conn, $uid, $maxAttachmentBytes, $stats);
				} catch (\Throwable $e) {
					// best-effort: one poison message must not kill the poll batch
					$stats['skipped']++;
					$this->logger->warning('kanzleicheck intake message failed', ['uid' => $uid, 'e' => $e->getMessage()]);
				}
				// mark seen either way — a poison message must not re-poll forever
				imap_setflag_full($conn, (string)$uid, '\\Seen', ST_UID);
			}
		} finally {
			imap_close($conn);
		}
		return [
			'polled' => (int)$stats['polled'],
			'stored' => (int)$stats['stored'],
			'held' => (int)$stats['held'],
			'skipped' => (int)$stats['skipped'],
			'error' => $stats['error'] === null ? null : (string)$stats['error'],
		];
	}

	private function processMessage(\IMAP\Connection $conn, int $uid, int $maxAttachmentBytes, array &$stats): void
	{
		$headers = imap_headerinfo($conn, $uid) ?: (object)[];
		$toRaw = imap_fetchheader($conn, $uid, FT_UID) ?: '';
		$parsed = null;
		foreach (['toaddress', 'to'] as $prop) {
			$addr = $headers->$prop ?? '';
			if (($parsed = IntakeService::parseAddress((string)$addr)) !== null) {
				break;
			}
		}
		if ($parsed === null && preg_match_all('/[^,\s<>]+@[^,\s<>]+/', $toRaw, $m)) {
			foreach ($m[0] as $candidate) {
				if (($parsed = IntakeService::parseAddress($candidate)) !== null) {
					break;
				}
			}
		}
		if ($parsed === null) {
			return; // not an intake-addressed mail — ignore entirely
		}
		$orgId = $parsed['orgId'];
		// invalid/rotated token → dropped silently (G-14: no oracle by mail)
		if (!$this->intake->isEnabled($orgId)
			|| !hash_equals($this->intake->currentToken($orgId), $parsed['token'])) {
			$stats['skipped']++;
			return;
		}
		$msgHash = hash('sha256', ($headers->message_id ?? '') . '|' . ($headers->date ?? '') . '|' . $uid);
		if ($this->holds->existsByMsgHash($msgHash)) {
			return; // replay-safe: never double-post
		}
		$from = strtolower((string)($headers->from[0]->mailbox ?? '') . '@' . ($headers->from[0]->host ?? ''));
		$senderHash = hash('sha256', $from);
		if (!$this->isMemberSender($orgId, $from)) {
			// non-member: hold for staff review, attachments quarantined
			$hold = $this->intake->hold($orgId, $msgHash, $senderHash);
			if ($hold !== null) {
				foreach ($this->extractParts($conn, $uid, $maxAttachmentBytes) as [$name, $tmp]) {
					$this->holdService->bufferFile((int)$hold->getId(), $name, $tmp);
					@unlink($tmp);
				}
			}
			$stats['held']++;
			return;
		}
		// member sender → ingest body + bounded attachments
		$stored = $this->storeAttachments($conn, $uid, $orgId, 'intake:' . $from, $maxAttachmentBytes, $stats);
		$body = $this->extractBody($conn, $uid);
		if ($body !== '') {
			$this->chat->post($orgId, 'intake:' . $senderHash, 'client', mb_substr($body, 0, ChatService::MAX_BODY), 'imap-' . $msgHash, null);
		}
		$stats['stored'] += $stored;
	}

	private function isMemberSender(int $orgId, string $from): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.identity_id')->from('kzc_member', 'm')
			->join('m', 'kzc_identity', 'i', 'i.id = m.identity_id')
			->where($qb->expr()->eq('m.org_id', $qb->createNamedParameter($orgId)))
			->andWhere($qb->expr()->eq('i.email', $qb->createNamedParameter($from)));
		return $qb->executeQuery()->fetchOne() !== false;
	}

	/** @return list<array{0:string,1:string}> [filename, tmpPath] extracted parts (caller unlinks) */
	private function extractParts(\IMAP\Connection $conn, int $uid, int $maxBytes): array
	{
		$out = [];
		$structure = imap_fetchstructure($conn, $uid, FT_UID);
		$count = 0;
		foreach (($structure->parts ?? []) as $idx => $part) {
			if ($count >= 25) {
				break;
			}
			$name = $this->partFilename($part) ?? 'attachment-' . ($idx + 1);
			$partNo = $idx + 1;
			if (($part->bytes ?? 0) > $maxBytes) {
				continue;
			}
			$data = imap_fetchbody($conn, $uid, (string)$partNo, FT_UID) ?: '';
			$data = match ((int)($part->encoding ?? 0)) {
				3 => base64_decode($data, true) ?: '',
				4 => quoted_printable_decode($data),
				default => $data,
			};
			if ($data === '') {
				continue;
			}
			$tmp = tempnam(sys_get_temp_dir(), 'kzc_in_');
			file_put_contents($tmp, $data);
			$out[] = [$name, $tmp];
			$count++;
		}
		return $out;
	}

	private function storeAttachments(\IMAP\Connection $conn, int $uid, int $orgId, string $actor, int $maxBytes, array &$stats): int
	{
		$stored = 0;
		foreach ($this->extractParts($conn, $uid, $maxBytes) as [$name, $tmp]) {
			$org = $this->orgOf($orgId);
			$folder = $org !== null ? $this->docs->orgFolder($orgId, $org->getName(), $org->getFolderId(), $org->getFolderPath()) : null;
			$eingang = $folder !== null ? $this->docs->ensureSubFolder($folder, DocumentService::DIR_INBOX) : null;
			try {
				if ($eingang === null) {
					throw new \RuntimeException('no eingang');
				}
				$this->uploads->validateFile($tmp, $name, $maxBytes);
				$this->uploads->store($eingang, $orgId, $actor, $tmp, $name, 'intake', 'per E-Mail');
				$stored++;
			} catch (\Throwable $e) {
				// best-effort: count + log the dropped attachment — silent inbound data loss otherwise
				$stats['skipped']++;
				$this->logger->warning('kanzleicheck intake attachment dropped', ['uid' => $uid, 'name' => (string)$name, 'e' => $e->getMessage()]);
			}
			@unlink($tmp);
		}
		return $stored;
	}

	private function partFilename(object $part): ?string
	{
		foreach (array_merge((array)($part->dparameters ?? []), (array)($part->parameters ?? [])) as $p) {
			if (in_array(strtolower($p->attribute ?? ''), ['filename', 'name'], true)) {
				return SafeFilenameService::sanitize($p->value ?? null);
			}
		}
		return null;
	}

	private function extractBody(\IMAP\Connection $conn, int $uid): string
	{
		$body = imap_body($conn, $uid, FT_UID) ?: '';
		$body = trim(strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		return $body;
	}

	private function orgOf(int $orgId): ?\OCA\KanzleiCheck\Db\Org
	{
		return $this->orgs->find($orgId);
	}
}

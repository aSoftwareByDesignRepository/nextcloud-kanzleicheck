<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\MailOutbox;
use OCA\PortalCheck\Db\MailOutboxMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Mail outbox (ZF-04/09): every outbound mail is a queued row — never an
 * inline IMailer::send in a request path (NN-P24). OTP/approval codes use
 * the same queue; the caller may request an immediate post-response drain.
 *
 * - idempotency key UNIQUE — retries never double-send
 * - batch drain 50/run, backoff 1→5→15→60 min, max 6 tries → failed
 * - cap 10,000 pending rows (OTP/approve kinds exempt) — beyond, non-OTP
 *   notices are dropped with a diagnostics entry, never queued unboundedly
 * - discard rows (AF-04): constant-path dummy rows for unknown e-mails,
 *   never delivered — no timing oracle on the send endpoint
 * - recipient stored ICrypto-encrypted until sent; to_hash for lookups
 */
class MailOutboxService
{
	public const MAX_PENDING = 10000;
	public const MAX_TRIES = 6;
	public const KINDS_NO_CAP = ['otp', 'approve'];

	public function __construct(
		private readonly MailOutboxMapper $outbox,
		private readonly IMailer $mailer,
		private readonly ICrypto $crypto,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Enqueue one mail. $params are template params — never content fields
	 * (NN-P04). Returns the row id (or existing row on idem replay).
	 */
	public function enqueue(string $to, string $kind, string $idemKey, array $params = [], string $locale = 'de', bool $discard = false): ?int
	{
		$to = strtolower(trim($to));
		$existing = $this->outbox->findByIdemKey($idemKey);
		if ($existing !== null) {
			return (int)$existing->getId();
		}
		if (!$discard && !in_array($kind, self::KINDS_NO_CAP, true)
			&& $this->outbox->pendingCount() >= self::MAX_PENDING) {
			$this->logger->warning('portalcheck outbox cap reached — dropping non-OTP mail', ['kind' => $kind]);
			return null;
		}
		$now = new \DateTimeImmutable();
		$o = new MailOutbox();
		$o->setIdemKey($idemKey);
		$o->setKind($kind);
		$o->setToHash(hash('sha256', $to));
		$o->setToEnc($this->crypto->encrypt($to));
		$o->setLocale($locale);
		$o->setParamsJson(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
		$o->setDiscard($discard);
		$o->setState($discard ? 'dropped' : 'pending');
		$o->setTries(0);
		$o->setNextTryAt($now);
		$o->setCreatedAt($now);
		try {
			$ins = $this->outbox->insert($o);
			return (int)$ins->getId();
		} catch (DbException $e) {
			// idem race: concurrent enqueue on the same idem_key — return the winner row
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& ($winner = $this->outbox->findByIdemKey($idemKey)) !== null) {
				return (int)$winner->getId();
			}
			throw $e;
		}
	}

	/**
	 * Drain up to $batch due rows. Claims each row CAS-style first — two
	 * job runs can never send the same mail (§9.9).
	 */
	public function drain(int $batch = 50): int
	{
		$sent = 0;
		foreach ($this->outbox->dueBatch(new \DateTimeImmutable(), $batch) as $row) {
			$id = (int)$row->getId();
			if (!$this->outbox->claim($id)) {
				continue; // another drainer won
			}
			if ((bool)$row->getDiscard()) {
				$this->finish($id, 'dropped');
				continue;
			}
			try {
				$to = $this->crypto->decrypt((string)$row->getToEnc());
				$this->send($to, (string)$row->getKind(), json_decode((string)($row->getParamsJson() ?? '{}'), true) ?: [], (string)($row->getLocale() ?? 'de'));
				$this->finish($id, 'sent');
				$sent++;
			} catch (\Throwable $e) {
				$this->retryOrFail($id, (int)$row->getTries(), $e);
			}
		}
		return $sent;
	}

	/** One mail through the instance's IMailer. Overridable seam for tests. */
	protected function send(string $to, string $kind, array $params, string $locale): void
	{
		$mail = MailRenderer::render($kind, $params, $locale);
		$message = $this->mailer->createMessage();
		$message->setTo([$to => $to]);
		$message->setSubject($mail['subject']);
		$message->setPlainBody($mail['text']);
		$this->mailer->send($message);
	}

	private function finish(int $id, string $state): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_mail_outbox')
			->set('state', $qb->createNamedParameter($state))
			->set('sent_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	private function retryOrFail(int $id, int $tries, \Throwable $e): void
	{
		$tries++;
		if ($tries >= self::MAX_TRIES) {
			$this->finish($id, 'failed');
			$this->logger->error('portalcheck outbox mail failed permanently', ['id' => $id, 'exception' => $e->getMessage()]);
			return;
		}
		$delay = [60, 300, 900, 3600, 3600][$tries - 1] ?? 3600;
		$next = (new \DateTimeImmutable())->modify('+' . $delay . ' seconds');
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_mail_outbox')
			->set('state', $qb->createNamedParameter('pending'))
			->set('tries', $qb->createNamedParameter($tries))
			->set('next_try_at', $qb->createNamedParameter($next, 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
		$this->logger->warning('portalcheck outbox mail retry scheduled', ['id' => $id, 'tries' => $tries]);
	}
}

<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\IntakeHold;
use OCA\PortalCheck\Db\IntakeHoldMapper;
use OCA\PortalCheck\Db\IntakeMapper;
use OCP\IDBConnection;

/**
 * Per-org e-mail intake (US-P24): address <local>+in-<orgId>-<token>@<domain>
 * with an HMAC token (16 chars, rotatable). Non-member senders → hold queue.
 * The IMAP pipeline itself is configured in settings; this service owns
 * address building, token rotation, and the hold queue lifecycle.
 */
class IntakeService
{
	public function __construct(
		private readonly IntakeMapper $intake,
		private readonly IntakeHoldMapper $holds,
		private readonly SettingsService $settings,
		private readonly TokenService $tokens,
		private readonly AuditService $audit,
	) {
	}

	private function secret(): string
	{
		$s = $this->settings->get('inbound_secret');
		if ($s === '') {
			$s = bin2hex(random_bytes(32));
			$this->settings->set('inbound_secret', $s);
		}
		return $s;
	}

	public function addressFor(int $orgId): ?string
	{
		$row = $this->intake->find($orgId);
		if ($row === null || !(int)$row['enabled']) {
			return null;
		}
		$local = $this->settings->get('inbound_local');
		$domain = $this->settings->get('inbound_domain');
		if ($local === '' || $domain === '') {
			return null;
		}
		return $local . '+in-' . $orgId . '-' . $this->currentToken($orgId) . '@' . $domain;
	}

	public function currentToken(int $orgId): string
	{
		return $this->tokens->intakeToken($this->secret(), $orgId);
	}

	public function setEnabled(string $actor, int $orgId, bool $enabled): void
	{
		$existing = $this->intake->find($orgId);
		$this->intake->upsert($orgId, $enabled, $existing['token_hash'] ?? $this->tokens->hash($this->currentToken($orgId)));
		$this->audit->log($actor, 'intake.' . ($enabled ? 'enable' : 'disable'), 'org', $orgId);
	}

	public function rotate(string $actor, int $orgId): void
	{
		// re-secret per org: hash a fresh random token bound to the row
		$new = bin2hex(random_bytes(8));
		$this->intake->upsert($orgId, true, $this->tokens->hash($new . ':' . $orgId));
		$this->audit->log($actor, 'intake.rotate', 'org', $orgId);
	}

	/** Parse "+in-<orgId>-<token>" recipients → orgId or null. */
	public static function parseAddress(string $to): ?array
	{
		if (preg_match('/\+in-(\d+)-([a-f0-9]{16})@/i', $to, $m)) {
			return ['orgId' => (int)$m[1], 'token' => strtolower($m[2])];
		}
		return null;
	}

	public function hold(int $orgId, string $msgHash, string $senderHash): void
	{
		if ($this->holds->existsByMsgHash($msgHash)) {
			return;
		}
		$h = new IntakeHold();
		$h->setOrgId($orgId);
		$h->setMsgHash($msgHash);
		$h->setSenderHash($senderHash);
		$h->setReceivedAt(new \DateTimeImmutable());
		$h->setState('held');
		$this->holds->insert($h);
	}
}

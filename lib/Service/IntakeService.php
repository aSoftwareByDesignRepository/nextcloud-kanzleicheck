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

	/**
	 * The live address token: HMAC(install secret, orgId | per-org nonce).
	 * The nonce lives in token_hash (it is entropy, not a credential);
	 * rotation replaces the nonce so the old token dies immediately (AC-6).
	 */
	public function currentToken(int $orgId): string
	{
		$row = $this->intake->find($orgId);
		$nonce = is_array($row) ? (string)($row['token_hash'] ?? '') : '';
		if ($nonce === '') {
			$nonce = $this->freshNonce();
			$this->intake->upsert($orgId, (int)($row['enabled'] ?? 0) === 1, $nonce);
		}
		return $this->tokens->intakeToken($this->secret() . ':' . $nonce, $orgId);
	}

	private function freshNonce(): string
	{
		return bin2hex(random_bytes(16));
	}

	public function setEnabled(string $actor, int $orgId, bool $enabled): void
	{
		$existing = $this->intake->find($orgId);
		$nonce = (string)($existing['token_hash'] ?? '') !== '' ? (string)$existing['token_hash'] : $this->freshNonce();
		$this->intake->upsert($orgId, $enabled, $nonce);
		$this->audit->log($actor, 'intake.' . ($enabled ? 'enable' : 'disable'), 'org', $orgId);
	}

	public function rotate(string $actor, int $orgId): void
	{
		// new nonce → new address token; the old address is dead from now on
		$this->intake->upsert($orgId, true, $this->freshNonce());
		$this->audit->log($actor, 'intake.rotate', 'org', $orgId);
	}

	public function isEnabled(int $orgId): bool
	{
		$row = $this->intake->find($orgId);
		return $row !== null && (int)$row['enabled'] === 1;
	}

	/** Parse "+in-<orgId>-<token>" recipients → orgId or null. */
	public static function parseAddress(string $to): ?array
	{
		if (preg_match('/\+in-(\d+)-([a-f0-9]{16})@/i', $to, $m)) {
			return ['orgId' => (int)$m[1], 'token' => strtolower($m[2])];
		}
		return null;
	}

	public function hold(int $orgId, string $msgHash, string $senderHash): ?IntakeHold
	{
		if ($this->holds->existsByMsgHash($msgHash)) {
			return null;
		}
		$h = new IntakeHold();
		$h->setOrgId($orgId);
		$h->setMsgHash($msgHash);
		$h->setSenderHash($senderHash);
		$h->setReceivedAt(new \DateTimeImmutable());
		$h->setState('held');
		return $this->holds->insert($h);
	}
}

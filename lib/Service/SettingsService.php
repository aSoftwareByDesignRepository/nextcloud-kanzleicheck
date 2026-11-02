<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * App-scoped settings. Booleans are parsed explicitly (`=== '1'`) — never
 * truthiness (form-encoded "false" is a truthy string, api-security §4).
 */
class SettingsService
{
	public const DEFAULTS = [
		'preset' => 'generic',
		'otp_ttl_min' => '10',
		'sess_days' => '30',
		'upload_max_mb' => '100',
		'poll_ms' => '15000',
		'audit_keep_days' => '1095',
		'approval_keep_days' => '3650',
		'docs_root_id' => '',
		'provider_label' => '',
		'allowed_groups' => '[]',
		'allowed_users' => '[]',
		'app_admins' => '[]',
		'inbound_host' => '',
		'inbound_port' => '993',
		'inbound_user' => '',
		'inbound_pass_enc' => '',
		'inbound_local' => '',
		'inbound_domain' => '',
		'inbound_enabled' => '0',
		'allow_http_login' => '0',
		'inbound_secret' => '',
		'inbound_cursor' => '',
	];

	public function __construct(
		private readonly IConfig $config,
		private readonly ?\OCP\Security\ICrypto $crypto = null,
	) {
	}

	public function get(string $key): string
	{
		$default = self::DEFAULTS[$key] ?? '';
		$v = $this->config->getAppValue(Application::APP_ID, $key, $default);
		return is_string($v) ? $v : $default;
	}

	public function getInt(string $key, int $fallback = 0): int
	{
		$v = $this->get($key);
		return $v !== '' && preg_match('/^-?\d+$/', $v) ? (int)$v : $fallback;
	}

	public function getBool(string $key): bool
	{
		return $this->get($key) === '1';
	}

	public function set(string $key, string $value): void
	{
		$this->config->setAppValue(Application::APP_ID, $key, $value);
	}

	public function setBool(string $key, bool $value): void
	{
		$this->set($key, $value ? '1' : '0');
	}

	/** @return list<string> */
	public function getList(string $key): array
	{
		$v = json_decode($this->get($key), true);
		return is_array($v) ? array_values(array_map('strval', $v)) : [];
	}

	/** @param list<string> $values */
	public function setList(string $key, array $values): void
	{
		$this->set($key, json_encode(array_values($values), JSON_UNESCAPED_SLASHES) ?: '[]');
	}

	/**
	 * Secret settings (mailbox password, …): ICrypto-encrypted at rest.
	 * Reading a legacy plaintext value returns it as-is (migration-safe);
	 * writes always encrypt when ICrypto is available.
	 */
	public function setSecret(string $key, string $value): void
	{
		if ($value !== '' && $this->crypto !== null) {
			try {
				$value = 'v2:' . $this->crypto->encrypt($value);
			} catch (\Throwable) {
			}
		}
		$this->set($key, $value);
	}

	public function getSecret(string $key): string
	{
		$v = $this->get($key);
		if ($v === '' || !str_starts_with($v, 'v2:') || $this->crypto === null) {
			return $v;
		}
		try {
			return $this->crypto->decrypt(substr($v, 3));
		} catch (\Throwable) {
			return '';
		}
	}
}

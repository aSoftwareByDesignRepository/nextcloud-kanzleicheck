<?php

declare(strict_types=1);

/**
 * KanzleiCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessDoorService::isAppAdmin — system admins + configured app admins).
 *  - validateValue/normalizeValue run the SAME rules the settings write
 *    path applies in StaffApiController::saveSettings/saveDoor (validator
 *    parity, NN-08 — import grants no bypass).
 *  - writeMirrored writes the door policy through
 *    AccessDoorService::saveDoor in ONE atomic call — never raw setAppValue
 *    (NN-02); the SYNC_DOOR_BINDINGS_LIVE fail-closed guard rides along.
 *  - createSafetySnapshot returns null: KanzleiCheck has no snapshot
 *    facility — the Service\UpgradeBackupService stub only carries the
 *    shared LOCK_KEY constant the engine locks against.
 */

namespace OCA\KanzleiCheck\ConfigTransfer;

use OCA\KanzleiCheck\Db\SyncBindingMapper;
use OCA\KanzleiCheck\Exception\ForbiddenException;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\PresetCatalog;
use OCA\KanzleiCheck\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const DOOR_KEYS = [
		'door_restricted',
		'allowed_groups',
		'allowed_users',
		'app_admins',
	];

	private const INT_KEYS = [
		'upload_max_mb',
		'poll_ms',
		'otp_ttl_min',
		'sess_days',
		'audit_keep_days',
		'approval_keep_days',
	];

	private const BOOL_KEYS = [
		'door_restricted',
		'allow_http_login',
		'inbound_enabled',
	];

	private const TRIM190_KEYS = [
		'inbound_host',
		'inbound_user',
		'inbound_local',
		'inbound_domain',
	];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessDoorService $door,
		private readonly AuditService $audit,
		private readonly SyncBindingMapper $syncBindings,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if ($uid === '' || !$this->door->isAppAdmin($uid)) {
			throw new ForbiddenException();
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		return match (true) {
			$key === 'preset' => in_array($value, PresetCatalog::IDS, true)
				? $value
				: throw new \InvalidArgumentException('INVALID_PRESET'),
			$key === 'provider_label' => mb_substr(trim($value), 0, 120),
			in_array($key, self::INT_KEYS, true) => preg_match('/^-?\d+$/', $value) === 1
				? (string)(int)$value
				: throw new \InvalidArgumentException('INVALID_INT'),
			$key === 'inbound_port' => preg_match('/^-?\d+$/', $value) === 1
				? (string)max(1, min(65535, (int)$value))
				: throw new \InvalidArgumentException('INVALID_PORT'),
			$key === 'docs_root_id' => preg_match('/^\d+$/', $value) === 1 || $value === ''
				? (string)(int)$value
				: throw new \InvalidArgumentException('INVALID_FILE_ID'),
			in_array($key, self::BOOL_KEYS, true) => $this->normalizeBool($value),
			in_array($key, self::TRIM190_KEYS, true) => mb_substr(trim($value), 0, 190),
			$key === 'allowed_groups', $key === 'allowed_users', $key === 'app_admins' => $this->normalizeIdList($value),
			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	/** Form-encoded bools arrive as strings (api-security §4) — '0'/'1'/'true'/'false' only. */
	private function normalizeBool(string $value): string
	{
		return match (strtolower(trim($value))) {
			'1', 'true' => '1',
			'0', 'false', '' => '0',
			default => throw new \InvalidArgumentException('INVALID_BOOL'),
		};
	}

	private function normalizeIdList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if (is_string($id) && $id !== '') {
				$clean[] = $id;
			}
		}
		return json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
	}

	public function validateMirrored(array $keyValues): ?string
	{
		$decoded = $this->decodeDoor($keyValues);
		if ($decoded === null) {
			return 'INVALID_POLICY_JSON';
		}
		[$restricted, $groups, $users, $appAdmins] = $decoded;
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				return 'INVALID_ALLOWED_GROUP';
			}
		}
		foreach (array_merge($users, $appAdmins) as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_ALLOWED_USER';
			}
		}
		if (!$restricted && $this->syncBindings->countNonTerminal() > 0) {
			return 'SYNC_DOOR_BINDINGS_LIVE';
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		$decoded = $this->decodeDoor($keyValues);
		if ($decoded === null) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		[$restricted, $groups, $users, $appAdmins] = $decoded;
		// One atomic policy write through the app's central writer — the
		// payload overlays onto the current door, so a partial transfer file
		// never blanks the lists it does not mention (decodeDoor overlays).
		$res = $this->door->saveDoor($restricted, $groups, $users, $appAdmins);
		if (!$res['ok']) {
			throw new \InvalidArgumentException($res['error'] !== '' ? $res['error'] : 'DOOR_WRITE_FAILED');
		}
	}

	/**
	 * Overlay the transferred mirrored keys onto the current door policy, so
	 * a file that carries only a subset never blanks the rest.
	 *
	 * @return array{0:bool,1:list<string>,2:list<string>,3:list<string>}|null
	 */
	private function decodeDoor(array $kv): ?array
	{
		$restricted = $this->settings->getBool('door_restricted');
		$groups = $this->settings->getList('allowed_groups');
		$users = $this->settings->getList('allowed_users');
		$appAdmins = $this->settings->getList('app_admins');
		if (isset($kv['door_restricted'])) {
			$v = strtolower(trim($kv['door_restricted']));
			if (!in_array($v, ['0', '1', 'true', 'false'], true)) {
				return null;
			}
			$restricted = $v === '1' || $v === 'true';
		}
		foreach (['allowed_groups', 'allowed_users', 'app_admins'] as $key) {
			if (!isset($kv[$key])) {
				continue;
			}
			$list = json_decode($kv[$key], true);
			if (!is_array($list)) {
				return null;
			}
			$clean = array_values(array_map('strval', $list));
			match ($key) {
				'allowed_groups' => $groups = $clean,
				'allowed_users' => $users = $clean,
				default => $appAdmins = $clean,
			};
		}
		return [$restricted, $groups, $users, $appAdmins];
	}

	public function securityRelevantKeys(): array
	{
		return [
			'door_restricted',
			'allowed_groups',
			'allowed_users',
			'app_admins',
			'allow_http_login',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		// No snapshot facility exists in KanzleiCheck (UpgradeBackupService
		// stub carries only the shared LOCK_KEY) — nothing to delegate to.
		return null;
	}

	public function settingsSectionForKey(string $key): ?string
	{
		if (in_array($key, self::DOOR_KEYS, true)) {
			return 'access';
		}
		// Internal bookkeeping keys never get a "set it on this instance"
		// link — there is no settings surface for them.
		if (in_array($key, ['inbound_cursor', 'uninstall_repair_pass', 'transfer_receipts', 'transfer_job_state', 'transfer_last_import_at'], true)) {
			return null;
		}
		return 'settings';
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			$this->audit->log(
				$actor === '' ? 'cli' : $actor,
				'config_transfer_' . $action,
				'config_transfer',
				0,
				$details,
			);
		} catch (\Throwable $e) {
			// audit must never fail the transfer itself
			$this->logger->warning('kanzleicheck: config transfer audit event failed', [
				'app' => 'kanzleicheck',
				'action' => $action,
				'exception' => $e,
			]);
		}
	}
}

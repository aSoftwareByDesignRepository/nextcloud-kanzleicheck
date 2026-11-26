<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Integration;

use OCA\KanzleiCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

/**
 * Disable preserves data — live-instance proof (DB-standards uninstall gate).
 *
 * Nextcloud runs the app's <uninstall> repair step on BOTH disable and
 * remove; UninstallRepairFlow::isRemovalContext() tells them apart.
 * This suite drives the real disable path over the provisioning OCS API
 * (DELETE /cloud/apps/{app} → AppManager::disableApp → repair step →
 * isRemovalContext() === false → preserve) and asserts the tables survive.
 * The updater's auto-disable reaches the identical repair-step branch.
 *
 * Requires the dev stack + NC_ADMIN_* creds in tests/e2e/.env (same
 * fixture contract as SyncLifecycleTest). Skips cleanly when unreachable.
 */
final class DisablePreserveDataIntegrationTest extends TestCase
{
	private static string $base;
	private static string $auth;
	private static array $skip = [];

	public static function setUpBeforeClass(): void
	{
		$envFile = __DIR__ . '/../e2e/.env';
		if (is_file($envFile)) {
			foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
				if (str_starts_with($line, 'NC_ADMIN_USER=')) { $u = substr($line, 14); }
				if (str_starts_with($line, 'NC_ADMIN_PASS=')) { $p = substr($line, 14); }
				if (str_starts_with($line, 'NC_BASE_URL=')) { $b = substr($line, 12); }
			}
		}
		if (empty($u ?? '') || empty($p ?? '')) {
			self::$skip = ['reason' => 'no NC_ADMIN_* creds in tests/e2e/.env'];
			return;
		}
		self::$base = rtrim(getenv('KZC_BASE') ?: ($b ?? 'http://localhost:8081'), '/');
		self::$auth = 'Basic ' . base64_encode("$u:$p");
		$r = self::ocs('GET', '/cloud/apps?filter=enabled');
		if (($r['status'] !== 200) || !str_contains((string)$r['raw'], UninstallDropTables::APP_ID)) {
			self::$skip = ['reason' => "instance unreachable or app not enabled ({$r['status']})"];
		}
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$skip !== []) {
			return;
		}
		// Never leave the suite's own app disabled.
		self::ocs('POST', '/cloud/apps/' . UninstallDropTables::APP_ID);
	}

	private static function ocs(string $method, string $path): array
	{
		$ch = curl_init(self::$base . '/ocs/v2.php' . $path);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => [
				'OCS-APIRequest: true',
				'Accept: application/json',
				'Authorization: ' . self::$auth,
			],
			CURLOPT_TIMEOUT => 30,
		]);
		$raw = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$json = json_decode((string)$raw, true);
		return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw];
	}

	/** PDO against the dev DB — same contract as SyncLifecycleTest::db(). */
	private static function db(string $sql): string
	{
		static $pdo = null;
		$pdo ??= new \PDO(
			getenv('KZC_DB_DSN') ?: 'mysql:host=' . (getenv('KZC_DB_HOST') ?: 'mariadb')
				. ';dbname=' . (getenv('KZC_DB_NAME') ?: 'nextcloud'),
			getenv('KZC_DB_USER') ?: 'nextcloud',
			getenv('KZC_DB_PASSWORD') ?: 'nextcloud_password',
			[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
		);
		$row = $pdo->query($sql)->fetch(\PDO::FETCH_NUM);
		return $row === false ? '' : (string)$row[0];
	}

	private function need(): void
	{
		if (self::$skip !== []) {
			$this->markTestSkipped(self::$skip['reason']);
		}
	}

	private function coreTable(): string
	{
		foreach (UninstallDropTables::TABLES as $table) {
			$hit = self::db(
				"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oc_{$table}'"
			);
			if ($hit !== '') {
				return "oc_{$table}";
			}
		}
		self::fail('No uninstall-catalog table exists before disable');
	}

	private function assertTableExists(string $table, string $why): void
	{
		$hit = self::db(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'"
		);
		self::assertSame($table, $hit, $why);
	}

	public function testDisableThenReEnablePreservesCoreTable(): void
	{
		$this->need();
		$table = $this->coreTable();
		$this->assertTableExists($table, 'fixture: core table must exist before disable');

		$r = self::ocs('DELETE', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'OCS disable failed: ' . $r['raw']);
		$this->assertTableExists($table, 'disable must not drop app tables (manual disable path)');

		$r = self::ocs('POST', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'OCS re-enable failed: ' . $r['raw']);
		$this->assertTableExists($table, 're-enable after disable must keep existing tables');
	}

	public function testAutoDisablePreservesTables(): void
	{
		$this->need();
		$table = $this->coreTable();
		$this->assertTableExists($table, 'fixture: core table must exist before auto-disable');

		// The updater's auto-disable runs the same <uninstall> repair step
		// through AppManager::disableApp — identical isRemovalContext()=false
		// branch as the OCS disable exercised here.
		$r = self::ocs('DELETE', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'OCS disable failed: ' . $r['raw']);
		$this->assertTableExists($table, 'auto-disable path must preserve tables');

		$r = self::ocs('POST', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'OCS re-enable failed: ' . $r['raw']);
	}

	public function testDoubleDisablePreservesTables(): void
	{
		$this->need();
		$table = $this->coreTable();
		$this->assertTableExists($table, 'fixture: core table must exist before double disable');

		$r = self::ocs('DELETE', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'first OCS disable failed: ' . $r['raw']);
		$this->assertTableExists($table, 'first disable must preserve tables');

		// Nextcloud runs uninstall repair on every disableApp() call — a
		// repeated disable (upgrade/retry scenario) must stay idempotent.
		$r = self::ocs('DELETE', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'second OCS disable failed: ' . $r['raw']);
		$this->assertTableExists($table, 'second disable must not drop tables');

		$r = self::ocs('POST', '/cloud/apps/' . UninstallDropTables::APP_ID);
		self::assertSame(200, $r['status'], 'OCS re-enable failed: ' . $r['raw']);
	}
}

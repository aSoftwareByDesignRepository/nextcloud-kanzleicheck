<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Live-instance integration suite — exercises the real Docker NC 35 stack
 * at KZC_BASE (default http://localhost:8081). Skips cleanly when the
 * instance is unreachable so the suite is CI-safe.
 *
 * These are the same adversarial proofs as tests/Integration/smoke.sh,
 * now runnable via `phpunit --testsuite integration`.
 */
class LiveInstanceTest extends TestCase
{
	private static string $base;
	private static string $cookieFile;
	private static array $skip = [];

	public static function setUpBeforeClass(): void
	{
		self::$base = rtrim(getenv('KZC_BASE') ?: 'http://localhost:8081', '/');
		self::$cookieFile = tempnam(sys_get_temp_dir(), 'kzc_ck_');
		$code = self::statusOf('/apps/kanzleicheck/portal/login');
		if ($code !== 200) {
			self::$skip = ['reason' => "instance unreachable ($code)"];
		}
	}

	private static function http(string $method, string $path, ?array $body = null, array $headers = [], bool $cookies = false): array
	{
		$url = self::$base . $path;
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
			CURLOPT_TIMEOUT => 15,
		]);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		if ($cookies) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, self::$cookieFile);
			curl_setopt($ch, CURLOPT_COOKIEFILE, self::$cookieFile);
		}
		$raw = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$json = json_decode((string)$raw, true);
		return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw];
	}

	private static function statusOf(string $path): int
	{
		$r = self::http('GET', $path);
		return $r['status'];
	}

	/**
	 * PDO against the dev DB; override with KZC_DB_DSN / KZC_DB_HOST /
	 * KZC_DB_NAME / KZC_DB_USER / KZC_DB_PASSWORD for host-side runs.
	 * Writes to oc_appconfig must outlive the AppConfig APCu blob
	 * (LOCAL_CACHE_TTL = 3 s in OC\AppConfig) before the next HTTP call,
	 * or the web workers keep serving the stale snapshot.
	 */
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
		if (str_starts_with(strtoupper(ltrim($sql)), 'SELECT')) {
			$row = $pdo->query($sql)->fetch(\PDO::FETCH_NUM);
			if ($row === false) {
				return '';
			}
			return implode("\t", array_map(fn($v) => $v === null ? 'NULL' : (string)$v, $row));
		}
		$pdo->exec($sql);
		if (str_contains($sql, 'oc_appconfig')) {
			usleep(3_500_000);
		}
		return '';
	}

	private function need(): void
	{
		if (self::$skip !== []) {
			$this->markTestSkipped(self::$skip['reason']);
		}
	}

	private static function seed(): void
	{
		self::db("DELETE FROM oc_kzc_member WHERE identity_id IN (SELECT id FROM oc_kzc_identity WHERE email='int@test.invalid');
DELETE FROM oc_kzc_identity WHERE email='int@test.invalid';
DELETE FROM oc_kzc_otp WHERE email='int@test.invalid';
DELETE FROM oc_kzc_org WHERE ref='INT-1';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('Int Mandat','INT-1','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_identity (email,display_name,status,created_at) VALUES ('int@test.invalid','Int Client','active',NOW());
INSERT INTO oc_kzc_member (identity_id,org_id,role,created_at,created_by) SELECT i.id,o.id,'member',NOW(),'system:test' FROM oc_kzc_identity i, oc_kzc_org o WHERE i.email='int@test.invalid' AND o.ref='INT-1';");
		self::db("INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('kanzleicheck','allow_http_login','1') ON DUPLICATE KEY UPDATE configvalue='1'");
	}

	private static function latestOtp(): string
	{
		return self::db("SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'\$.code')) FROM oc_kzc_mail_outbox WHERE kind='otp' ORDER BY id DESC LIMIT 1");
	}

	public function testPublicSurfaces(): void
	{
		$this->need();
		$this->assertSame(200, self::statusOf('/apps/kanzleicheck/portal/login'));
		$r = self::http('GET', '/apps/kanzleicheck/api/companion/v1/bootstrap');
		$this->assertTrue($r['json']['success']);
		$this->assertFalse($r['json']['data']['pushAvailable']);
	}

	public function testUnknownEmailUniform(): void
	{
		$this->need();
		$r = self::http('POST', '/apps/kanzleicheck/portal/api/auth/send', ['email' => 'nobody@x.invalid']);
		$this->assertTrue($r['json']['data']['sent'] ?? false);
		$this->assertSame(202, $r['status']);
	}

	public function testFullAuthRoundTrip(): string
	{
		$this->need();
		self::seed();
		self::http('POST', '/apps/kanzleicheck/portal/api/auth/send', ['email' => 'int@test.invalid']);
		$code = self::latestOtp();
		$this->assertMatchesRegularExpression('/^\d{6}$/', $code);
		$r = self::http('POST', '/apps/kanzleicheck/portal/api/auth/verify', ['email' => 'int@test.invalid', 'code' => $code], [], true);
		$this->assertTrue($r['json']['success']);
		$this->assertNotEmpty($r['json']['data']['csrf']);
		// orgs scoped
		$r = self::http('GET', '/apps/kanzleicheck/portal/api/orgs', null, [], true);
		$this->assertStringContainsString('Int Mandat', $r['raw']);
		return $code;
	}

	/**
	 * @depends testFullAuthRoundTrip
	 */
	public function testOtpReplayRejected(string $code): void
	{
		$this->need();
		$r = self::http('POST', '/apps/kanzleicheck/portal/api/auth/verify', ['email' => 'int@test.invalid', 'code' => $code]);
		$this->assertSame('INVALID_CODE', $r['json']['error']);
	}

	public function testCsrfAndIdor(): void
	{
		$this->need();
		// session from the round-trip cookies (testFullAuthRoundTrip ran first via @depends on class order — same cookie file)
		$r = self::http('POST', '/apps/kanzleicheck/portal/api/orgs/1/messages', ['body' => 'x'], [], true);
		$this->assertSame('UNAUTHORIZED', $r['json']['error']);
		$r = self::http('GET', '/apps/kanzleicheck/portal/api/orgs/999999/messages', null, [], true);
		$this->assertSame('NOT_FOUND', $r['json']['error']);
	}

	public function testStaffDoorAnonBlocked(): void
	{
		$this->need();
		$code = self::statusOf('/apps/kanzleicheck/api/portal/settings');
		$this->assertContains($code, [401, 403]);
	}
}

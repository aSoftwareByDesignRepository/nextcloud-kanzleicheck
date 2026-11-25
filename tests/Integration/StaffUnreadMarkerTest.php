<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Staff unread-badge lifecycle on the live instance:
 * client messages count as unread for a staff actor until POST
 * /api/portal/orgs/{orgId}/read advances the actor's monotonic marker.
 * An oversize lastReadId must clamp to the latest real message so future
 * mail still counts. Requires the dev stack + NC_ADMIN_* creds in
 * tests/e2e/.env (same fixture user as SyncLifecycleTest).
 */
final class StaffUnreadMarkerTest extends TestCase
{
	private static string $base;
	private static string $auth;
	private static string $staffUid = '';
	private static array $skip = [];
	private static int $orgId = 0;
	private static int $convId = 0;

	public static function setUpBeforeClass(): void
	{
		$envFile = __DIR__ . '/../e2e/.env';
		$u = $p = $b = null;
		if (is_file($envFile)) {
			foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
				if (str_starts_with($line, 'NC_ADMIN_USER=')) { $u = substr($line, 14); }
				if (str_starts_with($line, 'NC_ADMIN_PASS=')) { $p = substr($line, 14); }
				if (str_starts_with($line, 'NC_BASE_URL=')) { $b = substr($line, 12); }
			}
		}
		if (empty($u) || empty($p)) {
			self::$skip = ['reason' => 'no NC_ADMIN_* creds in tests/e2e/.env'];
			return;
		}
		self::$base = rtrim(getenv('KZC_BASE') ?: ($b ?? 'http://localhost:8081'), '/');
		self::$auth = 'Basic ' . base64_encode("$u:$p");
		self::$staffUid = (string)$u;
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/orgs');
		if ($r['status'] !== 200) {
			self::$skip = ['reason' => "staff API unreachable ({$r['status']})"];
		}
	}

	private static function http(string $method, string $path, ?array $body = null, bool $withAuth = true): array
	{
		$headers = ['Content-Type: application/json'];
		if ($withAuth) {
			$headers[] = 'Authorization: ' . self::$auth;
		}
		$ch = curl_init(self::$base . $path);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 20,
		]);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		$raw = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$json = json_decode((string)$raw, true);
		return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw];
	}

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
		return '';
	}

	private function need(): void
	{
		if (self::$skip !== []) {
			$this->markTestSkipped(self::$skip['reason']);
		}
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$orgId > 0) {
			self::db("DELETE FROM oc_kzc_message WHERE conv_id=" . self::$convId . ";
DELETE FROM oc_kzc_read WHERE conv_id=" . self::$convId . ";
DELETE FROM oc_kzc_conv WHERE id=" . self::$convId . ";
DELETE FROM oc_kzc_org WHERE id=" . self::$orgId . ";");
		}
	}

	private static function unreadFor(): int
	{
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/inbox');
		foreach (($r['json']['data']['conversations'] ?? []) as $c) {
			if ((int)$c['orgId'] === self::$orgId) {
				return (int)$c['unread'];
			}
		}
		return -1;
	}

	public function testSeedConvWithClientAndStaffMessages(): int
	{
		$this->need();
		self::db("DELETE FROM oc_kzc_message WHERE conv_id IN (SELECT id FROM oc_kzc_conv WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='UNREAD-1'));
DELETE FROM oc_kzc_read WHERE conv_id IN (SELECT id FROM oc_kzc_conv WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='UNREAD-1'));
DELETE FROM oc_kzc_conv WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='UNREAD-1');
DELETE FROM oc_kzc_org WHERE ref='UNREAD-1';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('Unread Mandat','UNREAD-1','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_conv (org_id,state,last_message_at,created_at) SELECT id,'open',NOW(),NOW() FROM oc_kzc_org WHERE ref='UNREAD-1';");
		self::$orgId = (int)self::db("SELECT id FROM oc_kzc_org WHERE ref='UNREAD-1'");
		self::$convId = (int)self::db("SELECT id FROM oc_kzc_conv WHERE org_id=" . self::$orgId);
		$this->assertGreaterThan(0, self::$convId);
		$staff = self::$staffUid;
		self::db("INSERT INTO oc_kzc_message (conv_id,author,author_kind,body,created_at) VALUES
(" . self::$convId . ",'unread-client@t.invalid','client','m1',NOW()),
(" . self::$convId . ",'unread-client@t.invalid','client','m2',NOW()),
(" . self::$convId . ",'" . addslashes($staff) . "','staff','own-reply',NOW())");
		return self::$convId;
	}

	/**
	 * @depends testSeedConvWithClientAndStaffMessages
	 */
	public function testUnreadCountsClientMessagesOnly(int $convId): int
	{
		$this->need();
		$this->assertSame(2, self::unreadFor()); // own staff reply excluded by actor
		return $convId;
	}

	/**
	 * @depends testUnreadCountsClientMessagesOnly
	 */
	public function testMarkReadPartialAdvance(int $convId): int
	{
		$this->need();
		$firstId = (int)self::db("SELECT id FROM oc_kzc_message WHERE conv_id=" . $convId . " ORDER BY id LIMIT 1");
		$this->assertGreaterThan(0, $firstId);
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/read', ['lastReadId' => $firstId]);
		$this->assertSame(204, $r['status']);
		$this->assertSame(1, self::unreadFor()); // monotonic advance, not all-clear
		return $convId;
	}

	/**
	 * @depends testMarkReadPartialAdvance
	 */
	public function testOversizeMarkerClampsAndFutureMailStillCounts(int $convId): void
	{
		$this->need();
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/read', ['lastReadId' => 999999999]);
		$this->assertSame(204, $r['status']);
		$this->assertSame(0, self::unreadFor());
		// A cursor parked ahead of every real id would swallow this message too —
		// the clamp must keep it at the latest existing message id.
		self::db("INSERT INTO oc_kzc_message (conv_id,author,author_kind,body,created_at) VALUES (" . $convId . ",'unread-client@t.invalid','client','m3',NOW())");
		$this->assertSame(1, self::unreadFor());
	}

	/**
	 * @depends testOversizeMarkerClampsAndFutureMailStillCounts
	 */
	public function testUnknownOrgUniform404(): void
	{
		$this->need();
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/999999999/read', ['lastReadId' => 1]);
		$this->assertSame('NOT_FOUND', $r['json']['error'] ?? '');
	}

	/**
	 * @depends testOversizeMarkerClampsAndFutureMailStillCounts
	 */
	public function testAnonymousBlocked(): void
	{
		$this->need();
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/read', ['lastReadId' => 1], false);
		$this->assertContains($r['status'], [401, 403]);
	}
}

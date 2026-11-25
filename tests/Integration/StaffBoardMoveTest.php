<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Staff board move (drag-drop + status select share PUT
 * /api/portal/tasks/{id}/move) on the live instance: the write is a CAS —
 * stale revisions conflict instead of silently overwriting, invalid
 * statuses are rejected, done_at follows the done column, and the door
 * holds for anonymous and unknown tasks. Requires the dev stack +
 * NC_ADMIN_* creds in tests/e2e/.env (same fixture as SyncLifecycleTest).
 */
final class StaffBoardMoveTest extends TestCase
{
	private static string $base;
	private static string $auth;
	private static array $skip = [];
	private static int $orgId = 0;
	private static int $taskId = 0;

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
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/board');
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
		if (self::$taskId > 0) {
			self::db("DELETE FROM oc_kzc_audit WHERE target_type='task' AND target_id=" . self::$taskId . ";");
		}
		if (self::$orgId > 0) {
			self::db("DELETE FROM oc_kzc_task WHERE org_id=" . self::$orgId . ";");
			self::db("DELETE FROM oc_kzc_org WHERE id=" . self::$orgId . ";");
		}
	}

	/** Column key holding our card on the board, or '' when absent. */
	private static function columnOfTask(): string
	{
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/board');
		foreach (($r['json']['data']['columns'] ?? []) as $status => $col) {
			foreach (($col['cards'] ?? []) as $card) {
				if ((int)$card['id'] === self::$taskId) {
					return (string)$status;
				}
			}
		}
		return '';
	}

	public function testSeedTaskOnBoard(): int
	{
		$this->need();
		self::db("DELETE FROM oc_kzc_task WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='DND-1');
DELETE FROM oc_kzc_org WHERE ref='DND-1';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('DnD Mandat','DND-1','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_task (org_id,title,status,priority,portal_visibility,revision,created_by,created_at,updated_at) SELECT id,'DndProbe','new','normal','status',1,'system:test',NOW(),NOW() FROM oc_kzc_org WHERE ref='DND-1';");
		self::$orgId = (int)self::db("SELECT id FROM oc_kzc_org WHERE ref='DND-1'");
		self::$taskId = (int)self::db("SELECT id FROM oc_kzc_task WHERE org_id=" . self::$orgId);
		$this->assertGreaterThan(0, self::$taskId);
		$this->assertSame('new', self::columnOfTask());
		return self::$taskId;
	}

	/**
	 * @depends testSeedTaskOnBoard
	 */
	public function testMoveToDoneSucceedsAndSetsDoneAt(int $taskId): int
	{
		$this->need();
		$rev = (int)self::db("SELECT revision FROM oc_kzc_task WHERE id=" . $taskId);
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId . '/move', ['status' => 'done', 'revision' => $rev]);
		$this->assertSame(200, $r['status'], $r['raw']);
		$this->assertTrue($r['json']['success'] ?? false);
		$this->assertSame($rev + 1, (int)$r['json']['data']['task']['revision']);
		$this->assertSame('done', self::columnOfTask());
		$this->assertNotSame('NULL', self::db("SELECT done_at FROM oc_kzc_task WHERE id=" . $taskId));
		return $taskId;
	}

	/**
	 * @depends testMoveToDoneSucceedsAndSetsDoneAt
	 */
	public function testStaleRevisionConflictsWithoutWriting(int $taskId): int
	{
		$this->need();
		$before = self::db("SELECT status FROM oc_kzc_task WHERE id=" . $taskId);
		// Revision 1 was already consumed by the first move — replaying it must
		// conflict, not silently overwrite (this is the concurrent-drag case).
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId . '/move', ['status' => 'waiting', 'revision' => 1]);
		$this->assertSame('CONFLICT', $r['json']['error'] ?? '');
		$this->assertSame($before, self::db("SELECT status FROM oc_kzc_task WHERE id=" . $taskId));
		$this->assertSame('done', self::columnOfTask());
		return $taskId;
	}

	/**
	 * @depends testStaleRevisionConflictsWithoutWriting
	 */
	public function testInvalidStatusRejectedWithoutWriting(int $taskId): int
	{
		$this->need();
		$rev = (int)self::db("SELECT revision FROM oc_kzc_task WHERE id=" . $taskId);
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId . '/move', ['status' => 'bogus', 'revision' => $rev]);
		$this->assertSame('VALIDATION_FAILED', $r['json']['error'] ?? '');
		$this->assertSame('done', self::db("SELECT status FROM oc_kzc_task WHERE id=" . $taskId));
		return $taskId;
	}

	/**
	 * @depends testInvalidStatusRejectedWithoutWriting
	 */
	public function testMoveBackClearsDoneAt(int $taskId): int
	{
		$this->need();
		$rev = (int)self::db("SELECT revision FROM oc_kzc_task WHERE id=" . $taskId);
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId . '/move', ['status' => 'waiting', 'revision' => $rev]);
		$this->assertTrue($r['json']['success'] ?? false, $r['raw']);
		$this->assertSame('waiting', self::columnOfTask());
		$this->assertSame('NULL', self::db("SELECT done_at FROM oc_kzc_task WHERE id=" . $taskId));
		return $taskId;
	}

	/**
	 * @depends testMoveBackClearsDoneAt
	 */
	public function testUnknownTaskUniform404(): void
	{
		$this->need();
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/999999999/move', ['status' => 'done', 'revision' => 1]);
		$this->assertSame('NOT_FOUND', $r['json']['error'] ?? '');
	}

	/**
	 * @depends testMoveBackClearsDoneAt
	 */
	public function testAnonymousBlocked(): void
	{
		$this->need();
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . self::$taskId . '/move', ['status' => 'done', 'revision' => 1], false);
		$this->assertContains($r['status'], [401, 403]);
	}

	/**
	 * Column order is server-side: dated cards first by due_date ASC,
	 * undated last (MariaDB would otherwise float NULLs to the top).
	 * @depends testSeedTaskOnBoard
	 */
	public function testBoardOrdersDatedAscThenUndated(): void
	{
		$this->need();
		self::db("INSERT INTO oc_kzc_task (org_id,title,status,priority,portal_visibility,revision,created_by,created_at,updated_at,due_date) VALUES
			(" . self::$orgId . ",'OrdUndated','new','normal','status',1,'system:test',NOW(),NOW(),NULL),
			(" . self::$orgId . ",'OrdLate','new','normal','status',1,'system:test',NOW(),NOW(),'2026-12-01'),
			(" . self::$orgId . ",'OrdEarly','new','normal','status',1,'system:test',NOW(),NOW(),'2026-11-15');");
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/board');
		$this->assertSame(200, $r['status'], $r['raw']);
		$titles = [];
		foreach (($r['json']['data']['columns']['new']['cards'] ?? []) as $card) {
			if ((int)$card['orgId'] === self::$orgId && str_starts_with((string)$card['title'], 'Ord')) {
				$titles[] = $card['title'];
			}
		}
		$this->assertSame(['OrdEarly', 'OrdLate', 'OrdUndated'], $titles);
	}
}

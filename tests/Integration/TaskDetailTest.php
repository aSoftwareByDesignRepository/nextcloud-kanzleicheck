<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * GET /api/portal/tasks/{id} — the detail endpoint behind the board card
 * dialog. The board payload stays lean; this proves the dialog gets every
 * editable + meta field, the CAS PUT round-trips edits (incl. clearing
 * assignee via ''), and the door holds. Requires the dev stack +
 * NC_ADMIN_* creds in tests/e2e/.env (same fixture as SyncLifecycleTest).
 */
final class TaskDetailTest extends TestCase
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

	public function testSeedTaskWithDetailFields(): int
	{
		$this->need();
		self::db("DELETE FROM oc_kzc_task WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='DET-1');
DELETE FROM oc_kzc_org WHERE ref='DET-1';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('Detail Mandat','DET-1','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_task (org_id,title,note,status,priority,portal_visibility,revision,created_by,assignee,created_at,updated_at,due_date) SELECT id,'DetailProbe','first note','new','normal','status',1,'system:test','staffer-a',NOW(),NOW(),'2026-12-24' FROM oc_kzc_org WHERE ref='DET-1';");
		self::$orgId = (int)self::db("SELECT id FROM oc_kzc_org WHERE ref='DET-1'");
		self::$taskId = (int)self::db("SELECT id FROM oc_kzc_task WHERE org_id=" . self::$orgId);
		$this->assertGreaterThan(0, self::$taskId);
		return self::$taskId;
	}

	/**
	 * @depends testSeedTaskWithDetailFields
	 */
	public function testDetailReturnsEveryDialogField(int $taskId): int
	{
		$this->need();
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/tasks/' . $taskId);
		$this->assertSame(200, $r['status'], $r['raw']);
		$d = $r['json']['data']['task'] ?? [];
		$this->assertSame($taskId, (int)$d['id']);
		$this->assertSame('DetailProbe', $d['title']);
		$this->assertSame('first note', $d['note']);
		$this->assertSame('new', $d['status']);
		$this->assertNotEmpty($d['statusLabel']);
		$this->assertSame('normal', $d['priority']);
		$this->assertSame('2026-12-24', $d['dueDate']);
		$this->assertSame('staffer-a', $d['assignee']);
		$this->assertSame('status', $d['visibility']);
		$this->assertSame(1, (int)$d['revision']);
		$this->assertSame(self::$orgId, (int)$d['orgId']);
		$this->assertSame('Detail Mandat', $d['orgName']);
		$this->assertSame('system:test', $d['createdBy']);
		$this->assertNotEmpty($d['createdAt']);
		$this->assertNotEmpty($d['updatedAt']);
		$this->assertNull($d['doneAt']);
		return $taskId;
	}

	/**
	 * @depends testDetailReturnsEveryDialogField
	 */
	public function testEditRoundTripsViaCasRevision(int $taskId): int
	{
		$this->need();
		$d = self::http('GET', '/apps/kanzleicheck/api/portal/tasks/' . $taskId)['json']['data']['task'];
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId, [
			'title' => 'DetailProbe v2',
			'note' => 'edited note',
			'priority' => 'urgent',
			'dueDate' => null,
			'assignee' => '',
			'revision' => (int)$d['revision'],
		]);
		$this->assertSame(200, $r['status'], $r['raw']);
		$this->assertSame((int)$d['revision'] + 1, (int)$r['json']['data']['task']['revision']);
		$after = self::http('GET', '/apps/kanzleicheck/api/portal/tasks/' . $taskId)['json']['data']['task'];
		$this->assertSame('DetailProbe v2', $after['title']);
		$this->assertSame('edited note', $after['note']);
		$this->assertSame('urgent', $after['priority']);
		$this->assertNull($after['dueDate']);
		$this->assertNull($after['assignee']); // '' clears — isset() would skip null
		return $taskId;
	}

	/**
	 * @depends testEditRoundTripsViaCasRevision
	 */
	public function testStaleRevisionConflictsWithoutWriting(int $taskId): int
	{
		$this->need();
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId, ['title' => 'sneaky', 'revision' => 1]);
		$this->assertSame('CONFLICT', $r['json']['error'] ?? '');
		$this->assertSame('DetailProbe v2', self::db("SELECT title FROM oc_kzc_task WHERE id=" . $taskId));
		return $taskId;
	}

	/**
	 * @depends testStaleRevisionConflictsWithoutWriting
	 */
	public function testEmptyTitleRejectedWithFieldError(int $taskId): int
	{
		$this->need();
		$rev = (int)self::db("SELECT revision FROM oc_kzc_task WHERE id=" . $taskId);
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/tasks/' . $taskId, ['title' => '   ', 'revision' => $rev]);
		$this->assertSame('VALIDATION_FAILED', $r['json']['error'] ?? '');
		$this->assertArrayHasKey('title', $r['json']['fields'] ?? []);
		$this->assertSame('DetailProbe v2', self::db("SELECT title FROM oc_kzc_task WHERE id=" . $taskId));
		return $taskId;
	}

	/**
	 * @depends testEmptyTitleRejectedWithFieldError
	 */
	public function testUnknownTaskUniform404(): void
	{
		$this->need();
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/tasks/999999999');
		$this->assertSame(404, $r['status']);
		$this->assertSame('NOT_FOUND', $r['json']['error'] ?? '');
	}

	/**
	 * @depends testEmptyTitleRejectedWithFieldError
	 */
	public function testAnonymousBlocked(): void
	{
		$this->need();
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/tasks/' . self::$taskId, null, false);
		$this->assertContains($r['status'], [401, 403]);
	}
}

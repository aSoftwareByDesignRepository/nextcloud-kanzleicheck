<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Live share-lifecycle integration (nc-user-binding spec §9): real Docker
 * NC 35 stack, real IShareManager — attach → mismatch gate → enable →
 * external share deletion → broken detection → repair → door guard →
 * revoke → unbind. Skips cleanly when the instance is unreachable.
 *
 * Staff calls use HTTP Basic auth with the atlas e2e user, which is
 * temporarily promoted to app_admins for the run (saved + restored).
 * The sync client is kzc_atlas_nonstaff — a real NC account OUTSIDE the
 * door lists whose account has no email, which exercises the durable
 * nc_uid_mismatch path for real.
 */
class SyncLifecycleTest extends TestCase
{
	private static string $base;
	private static string $auth;
	private static array $skip = [];
	private static int $orgId = 0;
	private static int $identityId = 0;
	private static string $origAdmins = '';
	private static string $origDoorRestricted = '';
	private static int $rootFileId = 0;
	private static bool $createdRootShare = false;

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
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/orgs');
		if (!in_array($r['status'], [200], true)) {
			self::$skip = ['reason' => "staff API unreachable ({$r['status']})"];
		}
	}

	private static function http(string $method, string $path, ?array $body = null, array $headers = []): array
	{
		$ch = curl_init(self::$base . $path);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => array_merge(
				['Content-Type: application/json', 'Authorization: ' . self::$auth],
				$headers),
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

	public static function tearDownAfterClass(): void
	{
		if (self::$skip !== []) {
			return;
		}
		// revoke any remaining binding shares before removing the seed data
		foreach (explode(',', self::db("SELECT GROUP_CONCAT(share_id) FROM oc_kzc_sync WHERE org_id=" . self::$orgId) ?: '') as $sid) {
			if ($sid !== '' && $sid !== '0') {
				self::db("DELETE FROM oc_share WHERE id=" . (int)$sid);
			}
		}
		if (self::$createdRootShare && self::$rootFileId > 0) {
			self::db("DELETE FROM oc_share WHERE share_with='kzc_atlas_e2e' AND item_source=" . self::$rootFileId);
		}
		self::db("DELETE FROM oc_kzc_sync WHERE org_id=" . self::$orgId . ";
DELETE FROM oc_kzc_member WHERE org_id=" . self::$orgId . ";
DELETE FROM oc_kzc_identity WHERE email='sync@t.invalid';
DELETE FROM oc_kzc_org WHERE ref='SYNC-1';");
		self::db(self::$origAdmins === ''
			? "DELETE FROM oc_appconfig WHERE appid='kanzleicheck' AND configkey='app_admins'"
			: "INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('kanzleicheck','app_admins','" . addslashes(self::$origAdmins) . "') ON DUPLICATE KEY UPDATE configvalue='" . addslashes(self::$origAdmins) . "'");
		self::db(self::$origDoorRestricted === ''
			? "DELETE FROM oc_appconfig WHERE appid='kanzleicheck' AND configkey='door_restricted'"
			: "INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('kanzleicheck','door_restricted','" . addslashes(self::$origDoorRestricted) . "') ON DUPLICATE KEY UPDATE configvalue='" . addslashes(self::$origDoorRestricted) . "'");
	}

	public function testSeedOrgIdentityAndAdminPromotion(): void
	{
		$this->need();
		self::$origAdmins = self::db("SELECT configvalue FROM oc_appconfig WHERE appid='kanzleicheck' AND configkey='app_admins'");
		$admins = json_decode(self::$origAdmins, true) ?: [];
		$admins = array_values(array_unique(array_merge($admins, ['kzc_atlas_e2e'])));
		self::db("INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('kanzleicheck','app_admins','" . addslashes(json_encode($admins)) . "') ON DUPLICATE KEY UPDATE configvalue='" . addslashes(json_encode($admins)) . "'");
		// door must be restricted for the suggest/bind/enable flow (D-B12);
		// capture the original value so tearDown can restore it
		self::$origDoorRestricted = self::db("SELECT configvalue FROM oc_appconfig WHERE appid='kanzleicheck' AND configkey='door_restricted'");
		self::db("INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('kanzleicheck','door_restricted','1') ON DUPLICATE KEY UPDATE configvalue='1'");
		self::db("DELETE FROM oc_kzc_member WHERE identity_id IN (SELECT id FROM oc_kzc_identity WHERE email='sync@t.invalid');
DELETE FROM oc_kzc_identity WHERE email='sync@t.invalid';
DELETE FROM oc_kzc_org WHERE ref='SYNC-1';
UPDATE oc_kzc_identity SET nc_uid=NULL, nc_uid_mismatch=0 WHERE nc_uid='kzc_atlas_nonstaff';
DELETE FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('Sync Mandat','SYNC-1','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_identity (email,display_name,status,created_at) VALUES ('sync@t.invalid','Sync Client','active',NOW());
INSERT INTO oc_kzc_member (identity_id,org_id,role,created_at,created_by) SELECT i.id,o.id,'member',NOW(),'system:test' FROM oc_kzc_identity i, oc_kzc_org o WHERE i.email='sync@t.invalid' AND o.ref='SYNC-1';");
		// share the configured docs root to the sync actor — ownership stays
		// with root; share mounts expose owner file ids inside the actor's
		// view, which is what the docs-root health check resolves against
		self::$rootFileId = (int)self::db("SELECT f.fileid FROM oc_filecache f JOIN oc_storages s ON f.storage=s.numeric_id WHERE f.path='files/KanzleiCheck' AND s.id='home::root'");
		if (self::$rootFileId > 0) {
			// reuse a pre-existing docs-root share (dev fixture); only tear down
			// a share this seed actually created, never the fixture's own row
			$have = self::db("SELECT id FROM oc_share WHERE share_with='kzc_atlas_e2e' AND item_source=" . self::$rootFileId . " AND accepted=1 LIMIT 1");
			if ($have === '' || $have === '0') {
				self::db("DELETE FROM oc_share WHERE share_with='kzc_atlas_e2e' AND item_source=" . self::$rootFileId);
				self::db("INSERT INTO oc_share (share_type,share_with,uid_owner,uid_initiator,item_type,item_source,item_target,file_source,file_target,permissions,stime,accepted) VALUES (0,'kzc_atlas_e2e','root','root','folder'," . self::$rootFileId . ",'/KanzleiCheck'," . self::$rootFileId . ",'/KanzleiCheck',31," . time() . ",1)");
				self::$createdRootShare = true;
			}
		}
		self::$orgId = (int)self::db("SELECT id FROM oc_kzc_org WHERE ref='SYNC-1'");
		self::$identityId = (int)self::db("SELECT id FROM oc_kzc_identity WHERE email='sync@t.invalid'");
		$this->assertGreaterThan(0, self::$orgId);
		$this->assertGreaterThan(0, self::$identityId);
	}

	/** @depends testSeedOrgIdentityAndAdminPromotion */
	public function testSuggestEndpointAndAttachGuards(): void
	{
		$this->need();
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-suggest');
		$this->assertSame(200, $r['status']);
		$this->assertTrue($r['json']['data']['doorRestricted']);
		$this->assertNull($r['json']['data']['ncUid']);
		// unknown uid → SYNC_UID_UNKNOWN
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-bind', ['ncUid' => 'no_such_user_xyz']);
		$this->assertSame('SYNC_UID_UNKNOWN', $r['json']['error']);
		// staff uid → SYNC_UID_IS_STAFF
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-bind', ['ncUid' => 'kzc_atlas_e2e']);
		$this->assertSame('SYNC_UID_IS_STAFF', $r['json']['error']);
	}

	/** @depends testSuggestEndpointAndAttachGuards */
	public function testAttachMismatchGateAndConfirm(): void
	{
		$this->need();
		// kzc_atlas_nonstaff has NO account email → mismatch gate fires
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-bind', ['ncUid' => 'kzc_atlas_nonstaff']);
		$this->assertSame(409, $r['status']);
		$this->assertSame('SYNC_EMAIL_MISMATCH', $r['json']['error']);
		// explicit accept_mismatch binds and persists the durable flag
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-bind', ['ncUid' => 'kzc_atlas_nonstaff', 'acceptMismatch' => '1']);
		$this->assertSame(200, $r['status']);
		$this->assertSame('kzc_atlas_nonstaff', $r['json']['data']['identity']['ncUid']);
		$this->assertTrue($r['json']['data']['identity']['ncUidMismatch']);
		$mismatch = self::db("SELECT nc_uid_mismatch FROM oc_kzc_identity WHERE id=" . self::$identityId);
		$this->assertSame('1', $mismatch);
	}

	/** @depends testAttachMismatchGateAndConfirm */
	public function testEnableCreatesRealShareWithoutReshareBit(): int
	{
		$this->need();
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/sync', ['identityId' => self::$identityId, 'perms' => 'rw']);
		$this->assertSame(201, $r['status'], $r['raw']);
		$this->assertContains($r['json']['data']['binding']['state'], ['active', 'pending_accept']);
		$bid = (int)$r['json']['data']['binding']['bindingId'];
		$row = self::db("SELECT share_type,share_with,permissions,uid_initiator FROM oc_share WHERE item_type='folder' AND share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e' ORDER BY id DESC LIMIT 1");
		$this->assertNotSame('', $row, 'no oc_share row created');
		[$type, $with, $perms, $by] = explode("\t", $row);
		$this->assertSame('0', $type); // user share
		$this->assertSame('kzc_atlas_nonstaff', $with);
		$this->assertSame('15', $perms); // read+update+create+delete — reshare bit (16) absent
		$this->assertSame('kzc_atlas_e2e', $by); // actor recorded
		// idempotent second enable → same binding, no duplicate share row
		// (snapshot compare — a shared dev instance may carry unrelated stale
		// shares to the same uid, so an absolute count is not a valid premise)
		$countBefore = self::db("SELECT COUNT(*) FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e'");
		$r2 = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/sync', ['identityId' => self::$identityId]);
		$this->assertSame($bid, (int)$r2['json']['data']['binding']['bindingId']);
		$countAfter = self::db("SELECT COUNT(*) FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e'");
		$this->assertSame($countBefore, $countAfter);
		return $bid;
	}

	/** @depends testEnableCreatesRealShareWithoutReshareBit */
	public function testExternalShareDeletionDetectedThenRepair(int $bid): void
	{
		$this->need();
		// simulate an out-of-band share removal (NN-11 read authority) —
		// scoped to THIS binding's share; a blanket uid delete would eat
		// foreign fixture shares on the shared dev instance
		self::db("DELETE FROM oc_share WHERE id IN (SELECT share_id FROM oc_kzc_sync WHERE id=$bid)");
		$r = self::http('GET', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/sync');
		$this->assertSame(200, $r['status']);
		$binding = $r['json']['data']['bindings'][0] ?? null;
		$this->assertNotNull($binding);
		$this->assertSame('broken_share', $binding['state']); // live verify sees it
		// one-click repair recreates the share
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/sync/' . $bid . '/repair', []);
		$this->assertSame(200, $r['status']);
		$this->assertContains($r['json']['data']['binding']['state'], ['active', 'pending_accept']);
		$count = self::db("SELECT COUNT(*) FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e'");
		$this->assertSame('1', $count, 'repair did not recreate the share');
	}

	/** @depends testEnableCreatesRealShareWithoutReshareBit */
	public function testDoorOpenRefusedWithLiveBindings(): void
	{
		$this->need();
		// D-B12: opening the door while bindings exist must be refused
		$current = self::db("SELECT configvalue FROM oc_appconfig WHERE appid='kanzleicheck' AND configkey='allowed_users'");
		$r = self::http('PUT', '/apps/kanzleicheck/api/portal/door', [
			'restricted' => '0',
			'groups' => [],
			'users' => json_decode($current ?: '[]', true),
			'appAdmins' => json_decode(self::$origAdmins ?: '[]', true),
		]);
		$this->assertSame(409, $r['status']);
		$this->assertSame('SYNC_DOOR_BINDINGS_LIVE', $r['json']['error']);
		// door stays restricted
		$this->assertSame('1', self::db("SELECT configvalue FROM oc_appconfig WHERE appid='kanzleicheck' AND configkey='door_restricted'"));
	}

	/** @depends testDoorOpenRefusedWithLiveBindings */
	public function testRevokeDeletesShareAndUnbindClears(): void
	{
		$this->need();
		$bid = (int)self::db("SELECT id FROM oc_kzc_sync WHERE org_id=" . self::$orgId . " ORDER BY id DESC LIMIT 1");
		$this->assertGreaterThan(0, $bid);
		$r = self::http('DELETE', '/apps/kanzleicheck/api/portal/sync/' . $bid);
		$this->assertSame(204, $r['status']);
		$this->assertSame('0', self::db("SELECT COUNT(*) FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e'"), 'share row not deleted');
		$this->assertSame('revoked', self::db("SELECT state FROM oc_kzc_sync WHERE id=$bid"));
		// unbind now allowed (all bindings terminal)
		$r = self::http('DELETE', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-bind');
		$this->assertSame(204, $r['status']);
		$this->assertSame('', self::db("SELECT COALESCE(nc_uid,'') FROM oc_kzc_identity WHERE id=" . self::$identityId));
	}

	/** @depends testRevokeDeletesShareAndUnbindClears */
	public function testEraseCascadeLeavesNcUserUntouched(): void
	{
		$this->need();
		// re-bind + re-enable so erase has something to revoke
		self::http('PUT', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '/nc-bind', ['ncUid' => 'kzc_atlas_nonstaff', 'acceptMismatch' => '1']);
		$r = self::http('POST', '/apps/kanzleicheck/api/portal/orgs/' . self::$orgId . '/sync', ['identityId' => self::$identityId]);
		$this->assertSame(201, $r['status'], $r['raw']);
		$this->assertSame('1', self::db("SELECT COUNT(*) FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e'"));
		// GDPR erase → share revoked, rows gone, nc_uid cleared, NC user alive
		$r = self::http('DELETE', '/apps/kanzleicheck/api/portal/identities/' . self::$identityId . '?deleteMessages=1');
		$this->assertContains($r['status'], [200, 204], $r['raw']);
		$this->assertSame('0', self::db("SELECT COUNT(*) FROM oc_share WHERE share_with='kzc_atlas_nonstaff' AND uid_initiator='kzc_atlas_e2e'"), 'erase left a dangling share');
		$this->assertSame('0', self::db("SELECT COUNT(*) FROM oc_kzc_sync WHERE identity_id=" . self::$identityId));
		$this->assertSame('1', self::db("SELECT COUNT(*) FROM oc_users WHERE uid='kzc_atlas_nonstaff'"), 'NC user was touched — D-B08 violation');
	}
}

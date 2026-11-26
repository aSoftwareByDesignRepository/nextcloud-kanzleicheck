// @ts-check
/**
 * Intake fix proof — client To-do must render cards when an item carries a
 * truthy rejectReason. Pre-fix todoCard() built `c` as a DocumentFragment
 * and called c.insertBefore(r, c.querySelector('.kzc-actions')) where the
 * ref node is NOT a direct child of the fragment → NotFoundError, so the
 * entire list died with a page error and nothing rendered.
 *
 * Self-provisioning with an owned fixture: a unique org + identity +
 * membership + request + delivered item is seeded per run, staff rejects
 * the item via the staff API, then the client portal renders the To-do
 * stack. A deterministic `LIMIT 1` pick over shared fixture rows collided
 * across concurrent farm runs — both runs landed on the same item AND the
 * same client identity, so OTP sends and the otp-hygiene DELETE raced the
 * sibling's login. Unique refs/emails remove the shared state entirely.
 * Teardown deletes the whole chain — nothing stays 'rejected'.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { createHash } = require('crypto');
const { hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web');
const COMPOSE_ROOT = path.resolve(__dirname, '../../..');
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

// Unique per run — two concurrent suites on the shared dev DB must not
// share fixture rows (farm runs overlap; OTP login is per-identity).
const TAG = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
const REF = 'TODO-REJ-E2E-1-' + TAG;
const ORG_NAME = 'Todo Rejected E2E ' + TAG;
const CLIENT_EMAIL = `todo-rej-${TAG}@t.invalid`;
const ITEM_LABEL = 'Beleg ' + TAG;
const REQ_TITLE = 'Unterlagen ' + TAG;
let orgId = 0;
let identityId = 0;
let reqId = 0;
let itemId = 0;

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
	if (r.status !== 0) {
		throw new Error(`db() failed rc=${r.status}: ${(r.stderr || '').trim()}`);
	}
	return (r.stdout || '').trim();
}

async function readOtp(email, tries = 30) {
	const sha = createHash('sha256').update(email).digest('hex');
	for (let i = 0; i < tries; i++) {
		const code = db("SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'$.code')) " +
			`FROM oc_kzc_mail_outbox WHERE kind='otp' AND to_hash='${sha}' ` +
			'AND discard=0 ORDER BY id DESC LIMIT 1;').split('\n').pop().trim();
		if (/^\d{6}$/.test(code)) return code;
		await new Promise((r) => setTimeout(r, 500));
	}
	throw new Error(`no OTP in mail outbox for ${email}`);
}

test.describe('client To-do with rejected item (intake fix)', () => {
	test.beforeAll(() => {
		// Unique chain: org + client identity + membership + open request +
		// delivered item. reviewItem() gates on item state only, so seeding
		// 'delivered' is equivalent to promoting an 'open' row.
		db(`DELETE FROM oc_kzc_req_item WHERE req_id IN (SELECT id FROM oc_kzc_req WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}'));
DELETE FROM oc_kzc_req WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}');
DELETE FROM oc_kzc_member WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}');
DELETE FROM oc_kzc_org WHERE ref='${REF}';
DELETE FROM oc_kzc_identity WHERE email='${CLIENT_EMAIL}';
DELETE FROM oc_kzc_otp WHERE email='${CLIENT_EMAIL}';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('${ORG_NAME}','${REF}','company','active',1,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_identity (email,display_name,status,created_at) VALUES ('${CLIENT_EMAIL}','Todo Rej E2E ${TAG}','active',NOW());
INSERT INTO oc_kzc_member (identity_id,org_id,role,created_at,created_by) SELECT i.id,o.id,'member',NOW(),'system:test' FROM oc_kzc_identity i, oc_kzc_org o WHERE i.email='${CLIENT_EMAIL}' AND o.ref='${REF}';
INSERT INTO oc_kzc_req (org_id,title,state,created_by,created_at) SELECT id,'${REQ_TITLE}','open','system:test',NOW() FROM oc_kzc_org WHERE ref='${REF}';
INSERT INTO oc_kzc_req_item (req_id,position,label,kind,state) SELECT id,0,'${ITEM_LABEL}','upload','delivered' FROM oc_kzc_req WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}');`);
		orgId = parseInt(db(`SELECT id FROM oc_kzc_org WHERE ref='${REF}'`), 10);
		identityId = parseInt(db(`SELECT id FROM oc_kzc_identity WHERE email='${CLIENT_EMAIL}'`), 10);
		reqId = parseInt(db(`SELECT id FROM oc_kzc_req WHERE org_id=${orgId}`), 10);
		itemId = parseInt(db(`SELECT id FROM oc_kzc_req_item WHERE req_id=${reqId}`), 10);
		test.skip(!(orgId > 0 && identityId > 0 && reqId > 0 && itemId > 0), 'seed failed');
	});

	test.afterAll(() => {
		if (itemId > 0) db(`DELETE FROM oc_kzc_req_item WHERE id=${itemId}`);
		if (reqId > 0) db(`DELETE FROM oc_kzc_req WHERE id=${reqId}`);
		if (orgId > 0) {
			db(`DELETE FROM oc_kzc_member WHERE org_id=${orgId};
DELETE FROM oc_kzc_org WHERE id=${orgId};`);
		}
		if (identityId > 0) {
			db(`DELETE FROM oc_kzc_otp WHERE email='${CLIENT_EMAIL}';
DELETE FROM oc_kzc_identity WHERE id=${identityId};`);
		}
	});

	test('cards render + reject reason visible + zero page errors', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const clientEmail = CLIENT_EMAIL;
		const orgName = ORG_NAME;

		// Staff rejects the seeded delivered item — fresh fixture, so the
		// review must land (409 tolerates a worker-restart double-fire).
		const REASON = 'intake-probe: scan illegible';
		const review = await request.put(
			`${APP}/api/portal/requests/${reqId}/items/${itemId}/review`,
			{
				data: { accept: false, reason: REASON },
				headers: { Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64') },
			});
		expect([200, 409].includes(review.status()), `review rc=${review.status()}`).toBeTruthy();
		// 409 = item already rejected (rerun) — reason is already set from the first run;
		// assert the fixture state directly instead of trusting the envelope.
		const st = db(`SELECT state, reject_reason FROM oc_kzc_req_item WHERE id=${itemId};`);
		expect(st.startsWith('rejected'), `item${itemId} state=${st}`).toBeTruthy();
		expect(st).toContain(REASON);

		const errors = [];
		page.on('pageerror', (e) => errors.push(String(e)));

		// OTP cap hygiene: fixed-identity fixture logs in via OTP — clear its
		// trailing-hour sends (per-email cap 5/h) so a suite re-run within the
		// hour cannot hit the discard path or replay a consumed code.
		db(`DELETE FROM oc_kzc_otp WHERE email='${clientEmail.replace(/'/g, "''")}';`);

		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#kzc-email').fill(clientEmail);
		await page.locator('#kzc-send-btn').click();
		await page.locator('#kzc-verify-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#kzc-code').fill(await readOtp(clientEmail));
		// 6-digit input auto-submits — no click: a second POST burns the shared
		// /portal/api/auth/verify AnonRateLimit budget (20/300s per IP, non-JSON
		// 429 → generic status line) twice per login and saturates the suite.
		await page.waitForURL('**/apps/kanzleicheck/portal**', { timeout: 20000 });

		// Multi-org identities land on the org picker first — pick the fixture
		// org. Button text is seeded fixture data (proper noun, locale-safe).
		// The picker mounts async after the orgs API returns — wait for either
		// branch (picker or direct To-do) before deciding.
		const pick = page.locator('.kzc-orgpick__btn', { hasText: orgName });
		await page.locator('.kzc-orgpick, .kzc-stack').first().waitFor({ state: 'visible', timeout: 15000 });
		if (await pick.count()) {
			await pick.first().click();
		}

		// To-do stack must mount with at least one card. Structural selectors —
		// locale-safe (no EN|DE visible-text match); the reason string is
		// seeded fixture data, not translated chrome.
		const card = page.locator('.kzc-stack .kzc-card');
		await expect(card.first()).toBeVisible({ timeout: 15000 });
		expect(await card.count()).toBeGreaterThanOrEqual(1);

		const err = page.locator('.kzc-card .kzc-field-error', { hasText: REASON });
		await expect(err.first()).toBeVisible();

		await page.screenshot({ path: path.join(OUT, 'todo-rejected-render.png'), fullPage: false });
		expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
	});
});

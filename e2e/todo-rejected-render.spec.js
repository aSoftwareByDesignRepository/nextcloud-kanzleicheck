// @ts-check
/**
 * Intake fix proof — client To-do must render cards when an item carries a
 * truthy rejectReason. Pre-fix todoCard() built `c` as a DocumentFragment
 * and called c.insertBefore(r, c.querySelector('.kzc-actions')) where the
 * ref node is NOT a direct child of the fragment → NotFoundError, so the
 * entire list died with a page error and nothing rendered.
 *
 * Self-provisioning: staff rejects a delivered request item via the staff
 * API, then the client portal renders the To-do stack. The fixture row is
 * resolved dynamically (rerun prefers the row this spec already rejected —
 * ids/names/emails are never hardcoded to a machine's seed). If no item is
 * delivered, an open one is promoted — reviewItem() gates on state only.
 * Teardown re-delivers nothing — fixture stays 'rejected'.
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

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
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
	test('cards render + reject reason visible + zero page errors', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();

		// Provision: resolve a deliverable item bound to an active member of an
		// app-enabled org — prefer the row a previous run already rejected so a
		// rerun is idempotent. Promote an open row to 'delivered' when needed.
		const REASON = 'intake-probe: scan illegible';
		const FX_SQL =
			'SELECT ri.id, ri.req_id, o.name, i.email, ri.state FROM oc_kzc_req_item ri ' +
			'JOIN oc_kzc_req r ON r.id=ri.req_id ' +
			'JOIN oc_kzc_org o ON o.id=r.org_id ' +
			"JOIN oc_kzc_member m ON m.org_id=r.org_id AND m.role='member' " +
			"JOIN oc_kzc_identity i ON i.id=m.identity_id AND i.status='active' " +
			`WHERE o.state='active' AND o.app_on=1 ` +
			`AND (ri.reject_reason='${REASON}' OR ri.state='delivered') ` +
			`ORDER BY (ri.reject_reason='${REASON}') DESC, ri.id LIMIT 1;`;
		let fx = db(FX_SQL);
		if (!fx) {
			db("UPDATE oc_kzc_req_item SET state='delivered' WHERE id = (" +
				'SELECT id FROM (SELECT ri.id FROM oc_kzc_req_item ri ' +
				'JOIN oc_kzc_req r ON r.id=ri.req_id ' +
				'JOIN oc_kzc_org o ON o.id=r.org_id ' +
				"JOIN oc_kzc_member m ON m.org_id=r.org_id AND m.role='member' " +
				"JOIN oc_kzc_identity i ON i.id=m.identity_id AND i.status='active' " +
				"WHERE ri.state='open' AND o.state='active' AND o.app_on=1 " +
				'ORDER BY ri.id LIMIT 1) pick);');
			fx = db(FX_SQL);
		}
		const [itemId, reqId, orgName, clientEmail] = fx.split('\t').map((s) => s.trim());
		expect(itemId, 'no deliverable fixture item resolved').toMatch(/^\d+$/);

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

// @ts-check
/**
 * Intake fix proof — client To-do must render cards when an item carries a
 * truthy rejectReason. Pre-fix todoCard() built `c` as a DocumentFragment
 * and called c.insertBefore(r, c.querySelector('.ptl-actions')) where the
 * ref node is NOT a direct child of the fragment → NotFoundError, so the
 * entire list died with a page error and nothing rendered.
 *
 * Self-provisioning: staff rejects the currently-delivered item 46 of
 * request 23 (org 104) via the staff API, then the client portal renders
 * the To-do stack. Teardown re-delivers nothing — fixture stays 'rejected'.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { createHash } = require('crypto');
const { hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/portalcheck/craft/web');
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
			`FROM oc_ptl_mail_outbox WHERE kind='otp' AND to_hash='${sha}' ` +
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

		// Provision: item 46 must be 'delivered' to review; ensure via staff reject.
		const review = await request.put(
			`${APP}/api/portal/requests/23/items/46/review`,
			{
				data: { accept: false, reason: 'intake-probe: scan illegible' },
				headers: { Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64') },
			});
		expect([200, 409].includes(review.status()), `review rc=${review.status()}`).toBeTruthy();
		// 409 = item already rejected (rerun) — reason is already set from the first run;
		// assert the fixture state directly instead of trusting the envelope.
		const st = db('SELECT state, reject_reason FROM oc_ptl_req_item WHERE id=46;');
		expect(st.startsWith('rejected'), `item46 state=${st}`).toBeTruthy();
		expect(st).toContain('intake-probe: scan illegible');

		const errors = [];
		page.on('pageerror', (e) => errors.push(String(e)));

		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#ptl-email').fill('lena.brandt@mueller-soehne.example');
		await page.locator('#ptl-send-btn').click();
		await page.locator('#ptl-verify-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#ptl-code').fill(await readOtp('lena.brandt@mueller-soehne.example'));
		await page.locator('#ptl-verify-btn').click();
		await page.waitForURL('**/apps/portalcheck/portal**', { timeout: 20000 });

		// Multi-org identities land on the org picker first — pick the fixture
		// org. Button text is seeded fixture data (proper noun, locale-safe).
		// The picker mounts async after the orgs API returns — wait for either
		// branch (picker or direct To-do) before deciding.
		const pick = page.locator('.ptl-orgpick__btn', { hasText: 'Müller & Söhne' });
		await page.locator('.ptl-orgpick, .ptl-stack').first().waitFor({ state: 'visible', timeout: 15000 });
		if (await pick.count()) {
			await pick.first().click();
		}

		// To-do stack must mount with at least one card. Structural selectors —
		// locale-safe (no EN|DE visible-text match); the reason string is
		// seeded fixture data, not translated chrome.
		const card = page.locator('.ptl-stack .ptl-card');
		await expect(card.first()).toBeVisible({ timeout: 15000 });
		expect(await card.count()).toBeGreaterThanOrEqual(1);

		const err = page.locator('.ptl-card .ptl-field-error', { hasText: 'intake-probe: scan illegible' });
		await expect(err.first()).toBeVisible();

		await page.screenshot({ path: path.join(OUT, 'todo-rejected-render.png'), fullPage: false });
		expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
	});
});

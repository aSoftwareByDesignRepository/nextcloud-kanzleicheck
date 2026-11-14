// @ts-check
/**
 * ATLAS kzc-c9 — tz_monoculture_dates executed proof. Civil-day surfaces
 * (request deadline render, form type=date answers) must be calendar-exact
 * under a negative-offset zone AND a >=UTC+11 zone — the N±1 shift class.
 * Both surfaces are YMD string surfaces: the spec asserts the exact stored
 * string renders verbatim and a written answer round-trips verbatim.
 */
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const { createHash } = require('crypto');
const { hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const COMPOSE_ROOT = require('path').resolve(__dirname, '../../..');
const authed = hasCreds();
const DAY = '2026-11-30';

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

async function portalLogin(page, email) {
	await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
	await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
	await page.locator('#kzc-email').fill(email);
	await page.locator('#kzc-send-btn').click();
	await page.locator('#kzc-verify-form').waitFor({ state: 'visible', timeout: 15000 });
	await page.locator('#kzc-code').fill(await readOtp(email));
	// 6-digit input auto-submits — no click: a second POST burns the shared
	// /portal/api/auth/verify AnonRateLimit budget (20/300s per IP, non-JSON
	// 429 → generic status line) twice per login and saturates the suite.
	await page.waitForURL('**/apps/kanzleicheck/portal**', { timeout: 20000 });
}

for (const zone of ['Pacific/Kiritimati', 'America/New_York']) {
	test.describe(`portal civil-date surfaces (${zone})`, () => {
		test.use({ timezoneId: zone });

		test(`deadline renders verbatim + date answer round-trips verbatim under ${zone}`, async ({ page, request }) => {
			test.skip(!authed, 'no creds');
			const { username, password } = creds();
			const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
			const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
			const email = `kzc-tz-${tag}@test.invalid`;
			const api = async (method, u, data) => request.fetch(`${BASE}${APP}${u}`, {
				method, data, headers: { Authorization: auth },
			}).then(async (r) => {
				const body = await r.text();
				return body ? JSON.parse(body) : { success: r.ok };
			});

			const org = (await api('POST', '/api/portal/orgs', { name: `TzOrg ${tag}`, ref: `TZ-${tag}`, kind: 'company' })).data.org;
			const m = await api('POST', `/api/portal/orgs/${org.id}/members`, { email, displayName: 'Tz Client' });
			expect(m.success).toBeTruthy();
			const req = await api('POST', '/api/portal/requests', {
				orgId: org.id, title: `TzReq ${tag}`, deadline: DAY,
				items: [{ label: `TzItem ${tag}`, kind: 'upload' }],
			});
			expect(req.success, JSON.stringify(req)).toBeTruthy();
			const tpl = await api('POST', '/api/portal/form-templates', {
				name: `TzTpl ${tag}`,
				fields: [{ key: 'day', label: 'Day', type: 'date', required: false }],
			});
			expect(tpl.success, JSON.stringify(tpl)).toBeTruthy();
			const form = await api('POST', '/api/portal/forms', { orgId: org.id, tplId: tpl.data.template.id });
			expect(form.success, JSON.stringify(form)).toBeTruthy();
			const formId = form.data.form.id;

			try {
				await portalLogin(page, email);
				await page.locator('#kzc-view h1').waitFor({ state: 'visible', timeout: 20000 });

				// Deadline card: the stored YMD renders verbatim — a Date-parse
				// surface would shift it ±1 under either of these zones.
				// (todo payload: request card title = item label, form title = 'Formular')
				const reqCard = page.locator('.kzc-card', { hasText: `TzItem ${tag}` });
				await reqCard.waitFor({ state: 'visible', timeout: 15000 });
				await expect(reqCard.locator('.kzc-muted')).toContainText(DAY);

				// Date answer: write through the real input, re-read from the API.
				const formCard = page.locator('.kzc-card', { hasText: 'Formular' });
				await formCard.locator('.kzc-btn').click();
				const dayInput = page.locator('input[type="date"]');
				await dayInput.waitFor({ state: 'visible', timeout: 15000 });
				const saved = page.waitForResponse(
					(r) => r.request().method() === 'PUT' && r.url().includes(`/portal/api/forms/${formId}/answers`),
					{ timeout: 15000 });
				await dayInput.fill(DAY);
				await dayInput.press('Tab');
				const resp = await saved;
				expect(resp.ok()).toBeTruthy();
				const reread = await page.evaluate(async (id) => {
					const base = location.pathname.split('/apps/kanzleicheck')[0] + '/apps/kanzleicheck';
					const r = await fetch(`${base}/portal/api/forms/${id}`);
					return r.json();
				}, formId);
				const ans = (reread.data?.form?.answers || []).find((a) => a.key === 'day');
				expect(ans?.value).toBe(DAY);
				await expect(dayInput).toHaveValue(DAY);
			} finally {
				try { await api('POST', `/api/portal/orgs/${org.id}/offboard`, {}); } catch { /* best effort */ }
				db("DELETE m FROM oc_kzc_member m JOIN oc_kzc_identity i ON m.identity_id=i.id " +
					"WHERE i.email LIKE 'kzc-tz-%@test.invalid';");
				db("DELETE FROM oc_kzc_identity WHERE email LIKE 'kzc-tz-%@test.invalid';");
			}
		});
	});
}

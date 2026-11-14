// @ts-check
/**
 * ATLAS portal multi-org picker (kzc-v13) — a portal identity linked to >1 org
 * must land on the org picker on boot (companion parity: App.tsx orgs.pick),
 * pick an org, and be able to switch back. Single-org identities keep the
 * direct auto-route. Writes craft PNGs to
 * .cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web/.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { createHash } = require('crypto');
const { hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract.js');

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

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

async function portalLogin(page, email) {
	await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
	await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
	await page.locator('#kzc-email').fill(email);
	await page.locator('#kzc-send-btn').click();
	await page.locator('#kzc-verify-form').waitFor({ state: 'visible', timeout: 15000 });
	await page.locator('#kzc-code').fill(await readOtp(email));
	await page.locator('#kzc-verify-btn').click();
	await page.waitForURL('**/apps/kanzleicheck/portal**', { timeout: 20000 });
}

test.describe('portal multi-org picker (v13)', () => {
	test('multi-org boot renders picker; pick routes; switch re-enters; single-org auto-routes', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const email = `kzc-orgpick-${tag}@test.invalid`;
		const emailSingle = `kzc-orgsingle-${tag}@test.invalid`;
		const nameA = `OrgPick Alpha ${tag}`;
		const nameB = `OrgPick Beta ${tag}`;
		const api = async (method, u, data) => request.fetch(`${BASE}${APP}${u}`, {
			method, data, headers: { Authorization: auth },
		}).then((r) => r.json());

		const o1 = (await api('POST', '/api/portal/orgs', { name: nameA, ref: `OPA-${tag}`, kind: 'company' })).data.org.id;
		const o2 = (await api('POST', '/api/portal/orgs', { name: nameB, ref: `OPB-${tag}`, kind: 'company' })).data.org.id;
		expect(o1).toBeGreaterThan(0);
		expect(o2).toBeGreaterThan(0);
		for (const oid of [o1, o2]) {
			const m = await api('POST', `/api/portal/orgs/${oid}/members`, { email, displayName: 'OrgPick Client' });
			expect(m.success, `addMember org ${oid}`).toBeTruthy();
		}
		const ms = await api('POST', `/api/portal/orgs/${o1}/members`, { email: emailSingle, displayName: 'Single Client' });
		expect(ms.success).toBeTruthy();

		try {
			/* ── multi-org: boot lands on the picker, not an auto-route ── */
			await page.setViewportSize({ width: 1280, height: 800 });
			await portalLogin(page, email);
			const pick = page.locator('.kzc-orgpick');
			await pick.waitFor({ state: 'visible', timeout: 20000 });
			await expect(pick.locator('.kzc-orgpick__btn')).toHaveCount(2);
			await assertAtlasRenderedSurface(page, {
				content: '#kzc-view',
				navExclude: '.kzc-tabbar, nav',
				listAllow: '.kzc-orgpick, .kzc-tabbar',
			});
			await shot(page, 'kzc-portal-orgpick-light-1280');

			/* pick Beta → routed into Beta's start view with org context + switch */
			await pick.locator('.kzc-orgpick__btn', { hasText: nameB }).click();
			await page.locator('#kzc-view h1').waitFor({ state: 'visible', timeout: 20000 });
			const ctx = page.locator('.kzc-orgctx');
			await ctx.waitFor({ state: 'visible', timeout: 15000 });
			await expect(ctx.locator('.kzc-muted')).toHaveText(nameB);

			/* re-entry: switch returns to the picker (no dead end) */
			await ctx.locator('.kzc-btn').click();
			await page.locator('.kzc-orgpick').waitFor({ state: 'visible', timeout: 15000 });
			await page.setViewportSize({ width: 375, height: 812 });
			await shot(page, 'kzc-portal-orgpick-light-375');

			/* pick Alpha → routed into Alpha */
			await page.locator('.kzc-orgpick__btn', { hasText: nameA }).click();
			await page.locator('.kzc-orgctx').waitFor({ state: 'visible', timeout: 15000 });
			await expect(page.locator('.kzc-orgctx .kzc-muted')).toHaveText(nameA);

			/* ── single-org: auto-route keeps working, no picker, no switch ── */
			await page.context().clearCookies();
			await page.setViewportSize({ width: 1280, height: 800 });
			await portalLogin(page, emailSingle);
			await page.locator('#kzc-view h1').waitFor({ state: 'visible', timeout: 20000 });
			await expect(page.locator('.kzc-orgpick')).toHaveCount(0);
			await expect(page.locator('.kzc-orgctx')).toHaveCount(0);
		} finally {
			// teardown: offboard fixture orgs, drop member+identity rows
			try { await api('POST', `/api/portal/orgs/${o1}/offboard`, {}); } catch { /* best effort */ }
			try { await api('POST', `/api/portal/orgs/${o2}/offboard`, {}); } catch { /* best effort */ }
			db("DELETE m FROM oc_kzc_member m JOIN oc_kzc_identity i ON m.identity_id=i.id " +
				"WHERE i.email LIKE 'kzc-orgpick-%@test.invalid' OR i.email LIKE 'kzc-orgsingle-%@test.invalid';");
			db("DELETE FROM oc_kzc_identity WHERE email LIKE 'kzc-orgpick-%@test.invalid' " +
				"OR email LIKE 'kzc-orgsingle-%@test.invalid';");
		}
	});
});

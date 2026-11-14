// @ts-check
/**
 * ATLAS web craft capture — writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web/.
 * Surfaces: guest login (light/dark/HC), staff overview/board/orgs/settings
 * (light/dark), 404 shell. Viewports 1280/768/375.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web');
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

const VIEWPORTS = [
	['1280', { width: 1280, height: 800 }],
	['768', { width: 768, height: 1024 }],
	['375', { width: 375, height: 812 }],
];

test.describe('craft web', () => {
	for (const [tag, vp] of VIEWPORTS) {
		test(`guest login light ${tag}`, async ({ page }) => {
			await page.setViewportSize(vp);
			await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
			await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
			await shot(page, `kzc-portal-login-light-${tag}`);
		});
	}
	test('guest login dark 1280', async ({ page }) => {
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await shot(page, 'kzc-portal-login-dark-1280');
	});
	test('guest login hc 1280', async ({ page }) => {
		await page.emulateMedia({ forcedColors: 'active' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
		// kzc-v15: under forced colors the primary CTA must keep a visible
		// boundary — bare text fails WCAG 1.4.11.
		const bw = await page.locator('#kzc-send-btn').evaluate((el) => getComputedStyle(el).borderTopWidth);
		if (parseFloat(bw) < 1) throw new Error(`forced-colors CTA border missing (borderTopWidth=${bw})`);
		await shot(page, 'kzc-portal-login-hc-1280');
	});
	test('guest notfound light 1280', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/u/does-not-exist`, { waitUntil: 'domcontentloaded' });
		await shot(page, 'kzc-portal-notfound-light-1280');
	});

	const STAFF_VIEWS = [
		['overview', null],
		['board', 'board'],
		['requests', 'requests'],
		['orgs', 'orgs'],
		['uploads', 'uploads'],
		['settings', 'settings'],
	];
	for (const [name, nav] of STAFF_VIEWS) {
		test(`staff ${name} light 1280`, async ({ page }) => {
			test.skip(!authed, 'no creds');
			await page.setViewportSize({ width: 1280, height: 800 });
			await login(page, creds());
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
			await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
			if (nav) await page.locator(`.kzc-navbtn[data-v="${nav}"]`).click();
			if (name === 'overview') {
				// fragment-crash regression: .kzc-row only exists if vOverview ran to completion
				await page.locator('#kzc-body .kzc-list .kzc-row').first().waitFor({ state: 'attached', timeout: 15000 });
			}
			await page.waitForTimeout(1200);
			await shot(page, `kzc-staff-${name}-light-1280`);
		});
	}
	test('staff overview dark 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('#kzc-body .kzc-list .kzc-row').first().waitFor({ state: 'attached', timeout: 15000 });
		await page.waitForTimeout(1200);
		await shot(page, 'kzc-staff-overview-dark-1280');
	});
	test('staff overview mobile 375', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 375, height: 812 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('#kzc-body .kzc-list .kzc-row').first().waitFor({ state: 'attached', timeout: 15000 });
		await page.waitForTimeout(1200);
		await shot(page, 'kzc-staff-overview-light-375');
	});

	/* v7 coverage: dialog + filter + field-error + deny surfaces */
	test('staff requests new-request dialog 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn[data-v="requests"]').click();
		await page.locator('#kzc-mine').waitFor({ state: 'visible', timeout: 20000 });
		// fragment-crash regression: .kzc-list only exists if vRequests ran to completion
		await page.locator('#kzc-body .kzc-list').waitFor({ state: 'attached', timeout: 15000 });
		await page.locator('.kzc-btn--primary').first().click();
		await page.locator('dialog.kzc-dialog[open]').waitFor({ state: 'visible', timeout: 10000 });
		await shot(page, 'kzc-staff-requests-dialog-light-1280');
	});
	test('staff requests mine-filter on 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn[data-v="requests"]').click();
		const mine = page.locator('#kzc-mine');
		await mine.waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('#kzc-body .kzc-list').waitFor({ state: 'attached', timeout: 15000 });
		await mine.check();
		// toggle must complete a re-render — the list container is re-appended
		await page.locator('#kzc-body .kzc-list').waitFor({ state: 'attached', timeout: 15000 });
		await page.waitForTimeout(600);
		await shot(page, 'kzc-staff-requests-filter-light-1280');
	});
	test('staff inbox list renders 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn[data-v="inbox"]').click();
		// vInbox completes only when a list or a muted empty/error line lands
		await page.locator('#kzc-body .kzc-list, #kzc-body .kzc-muted, #kzc-body p').first().waitFor({ state: 'attached', timeout: 15000 });
		await page.waitForTimeout(600);
		await shot(page, 'kzc-staff-inbox-light-1280');
	});
	test('guest login invalid-code field-error 1280', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#kzc-email').fill('kzc-craft-invalid@test.invalid');
		await page.locator('#kzc-send-form button[type="submit"], #kzc-send-form .kzc-btn--primary').first().click();
		const verify = page.locator('#kzc-verify-form');
		await verify.waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#kzc-code').fill('000000');
		await verify.locator('button[type="submit"], .kzc-btn--primary').first().click();
		await page.locator('.kzc-field-error:not([hidden])').first().waitFor({ state: 'visible', timeout: 15000 });
		// kzc-v14: one failure, one surface — a field-scoped error must NOT be
		// mirrored verbatim into the status line.
		const st = (await page.locator('#kzc-login-status').textContent()) || '';
		if (st.trim() !== '') throw new Error(`field-scoped error duplicated into status line: ${st.trim()}`);
		await shot(page, 'kzc-portal-login-fielderror-light-1280');
	});
	/* c2 deeplink: notification mails land ?view=inbox&org=N — boot must open
	 * that org's conversation, not the unconditional overview. */
	test('staff deeplink notification inbox+org 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		const orgId = await page.evaluate(async () => {
			const r = await fetch('api/portal/orgs', { headers: { Accept: 'application/json' } }).then((x) => x.json());
			const orgs = (r && r.data && r.data.orgs) || [];
			return orgs.length ? orgs[0].id : 0;
		});
		test.skip(!orgId, 'no org fixture');
		await page.goto(`${APP}/?view=inbox&org=${orgId}`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn--active[data-v="inbox"]').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#kzc-msgs').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#kzc-cf').waitFor({ state: 'visible', timeout: 15000 });
		await shot(page, 'kzc-staff-deeplink-inbox-light-1280');
	});
	test('staff deeplink bogus org falls back to inbox list 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/?view=inbox&org=99999999`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn--active[data-v="inbox"]').waitFor({ state: 'visible', timeout: 15000 });
		await page.waitForTimeout(1200);
		const composer = await page.locator('#kzc-cf').count();
		if (composer !== 0) throw new Error('bogus org opened a conversation composer');
	});
	test('staff deeplink non-numeric org falls back 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/?view=inbox&org=abc`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn--active[data-v="inbox"]').waitFor({ state: 'visible', timeout: 15000 });
	});
	test('staff settings deny nonstaff 1280', async ({ page }) => {
		const u = process.env.NC_NONSTAFF_USER, p = process.env.NC_NONSTAFF_PASS;
		test.skip(!(u && p), 'no nonstaff creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, { username: u, password: p });
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.kzc-navbtn[data-v="settings"]').click();
		await page.waitForTimeout(1500);
		await shot(page, 'kzc-staff-settings-deny-light-1280');
	});
});

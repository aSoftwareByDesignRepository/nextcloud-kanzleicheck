// @ts-check
/**
 * ATLAS web craft capture — writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/portalcheck/craft/web/.
 * Surfaces: guest login (light/dark/HC), staff overview/board/orgs/settings
 * (light/dark), 404 shell. Viewports 1280/768/375.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/portalcheck/craft/web');
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
			await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
			await shot(page, `ptl-portal-login-light-${tag}`);
		});
	}
	test('guest login dark 1280', async ({ page }) => {
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await shot(page, 'ptl-portal-login-dark-1280');
	});
	test('guest login hc 1280', async ({ page }) => {
		await page.emulateMedia({ forcedColors: 'active' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await shot(page, 'ptl-portal-login-hc-1280');
	});
	test('guest notfound light 1280', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/u/does-not-exist`, { waitUntil: 'domcontentloaded' });
		await shot(page, 'ptl-portal-notfound-light-1280');
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
			await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
			if (nav) await page.locator(`.ptl-navbtn[data-v="${nav}"]`).click();
			await page.waitForTimeout(1200);
			await shot(page, `ptl-staff-${name}-light-1280`);
		});
	}
	test('staff overview dark 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.waitForTimeout(1200);
		await shot(page, 'ptl-staff-overview-dark-1280');
	});
	test('staff overview mobile 375', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 375, height: 812 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.waitForTimeout(1200);
		await shot(page, 'ptl-staff-overview-light-375');
	});

	/* v7 coverage: dialog + filter + field-error + deny surfaces */
	test('staff requests new-request dialog 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.ptl-navbtn[data-v="requests"]').click();
		await page.locator('#ptl-mine').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.ptl-btn--primary').first().click();
		await page.locator('dialog.ptl-dialog[open]').waitFor({ state: 'visible', timeout: 10000 });
		await shot(page, 'ptl-staff-requests-dialog-light-1280');
	});
	test('staff requests mine-filter on 1280', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.ptl-navbtn[data-v="requests"]').click();
		const mine = page.locator('#ptl-mine');
		await mine.waitFor({ state: 'visible', timeout: 20000 });
		await mine.check();
		await page.waitForTimeout(1200);
		await shot(page, 'ptl-staff-requests-filter-light-1280');
	});
	test('guest login invalid-code field-error 1280', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#ptl-email').fill('ptl-craft-invalid@test.invalid');
		await page.locator('#ptl-send-form button[type="submit"], #ptl-send-form .ptl-btn--primary').first().click();
		const verify = page.locator('#ptl-verify-form');
		await verify.waitFor({ state: 'visible', timeout: 15000 });
		await page.locator('#ptl-code').fill('000000');
		await verify.locator('button[type="submit"], .ptl-btn--primary').first().click();
		await page.locator('.ptl-field-error:not([hidden])').first().waitFor({ state: 'visible', timeout: 15000 });
		await shot(page, 'ptl-portal-login-fielderror-light-1280');
	});
	test('staff settings deny nonstaff 1280', async ({ page }) => {
		const u = process.env.NC_NONSTAFF_USER, p = process.env.NC_NONSTAFF_PASS;
		test.skip(!(u && p), 'no nonstaff creds');
		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, { username: u, password: p });
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.ptl-navbtn[data-v="settings"]').click();
		await page.waitForTimeout(1500);
		await shot(page, 'ptl-staff-settings-deny-light-1280');
	});
});

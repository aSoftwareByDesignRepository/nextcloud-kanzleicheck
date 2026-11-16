// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — rendered truth of KanzleiCheck page
 * surfaces: list markers survive core resets, selects vertically centred,
 * icons non-zero, form controls not centred, NC-core 130px pin defeated.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('./helpers/auth.js');
const { assertAtlasRenderedSurface } = require('../../../_shared/e2e/atlas-rendered-surface-contract.js');

const APP = '/index.php/apps/kanzleicheck';
const authed = hasCreds();

test.describe('Rendered-surface contract', () => {
	test('ATLAS_RENDERED_SURFACE_CONTRACT guest login (/portal/login)', async ({ page }) => {
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await assertAtlasRenderedSurface(page, {
			content: '.kzc-portal',
			navExclude: 'nav',
			listAllow: '.kzc-tabbar',
			// OTP code box: centered + letter-spaced is the deliberate
			// one-time-code anatomy, not inherited shell centering.
			centerAllow: '.kzc-input--code',
		});
	});

	test('ATLAS_RENDERED_SURFACE_CONTRACT staff overview (/)', async ({ page }) => {
		test.skip(!authed, 'no E2E creds');
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#app-navigation .kzc-nav__link').first().waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('#kzc-staff-view #kzc-body').waitFor({ state: 'visible', timeout: 20000 });
		await assertAtlasRenderedSurface(page, {
			content: '#kzc-staff-view',
			navExclude: '.kzc-nav, nav, #app-navigation',
			listAllow: '.kzc-stat-strip, .kzc-cardmeta, .kzc-board, .kzc-list, .kzc-kvrow, .kzc-dev, .kzc-actions, .kzc-itemlist, .kzc-sync-list, .kzc-quick-filters, .kzc-filter-grid',
		});
	});

	test('ATLAS_RENDERED_SURFACE_CONTRACT staff settings view', async ({ page }) => {
		test.skip(!authed, 'no E2E creds');
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#app-navigation .kzc-nav__link').first().waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('#kzc-staff-view #kzc-body').waitFor({ state: 'visible', timeout: 20000 });
		await page.goto(`${APP}/settings/general`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-body .kzc-card').first().waitFor({ state: 'visible', timeout: 15000 });
		await assertAtlasRenderedSurface(page, {
			content: '#kzc-staff-view',
			navExclude: '.kzc-nav, nav, #app-navigation',
			listAllow: '.kzc-stat-strip, .kzc-cardmeta, .kzc-board, .kzc-list, .kzc-kvrow, .kzc-dev, .kzc-actions, .kzc-itemlist, .kzc-sync-list, .kzc-quick-filters, .kzc-filter-grid',
		});
	});
});

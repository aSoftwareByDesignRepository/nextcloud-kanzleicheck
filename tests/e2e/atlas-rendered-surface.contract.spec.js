// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — rendered truth of PortalCheck page
 * surfaces: list markers survive core resets, selects vertically centred,
 * icons non-zero, form controls not centred, NC-core 130px pin defeated.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('./helpers/auth.js');
const { assertAtlasRenderedSurface } = require('../../../_shared/e2e/atlas-rendered-surface-contract.js');

const APP = '/index.php/apps/portalcheck';
const authed = hasCreds();

test.describe('Rendered-surface contract', () => {
	test('ATLAS_RENDERED_SURFACE_CONTRACT guest login (/portal/login)', async ({ page }) => {
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await assertAtlasRenderedSurface(page, {
			content: '.ptl-portal',
			navExclude: 'nav',
			listAllow: '.ptl-tabbar',
		});
	});

	test('ATLAS_RENDERED_SURFACE_CONTRACT staff overview (/)', async ({ page }) => {
		test.skip(!authed, 'no E2E creds');
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await assertAtlasRenderedSurface(page, {
			content: '#ptl-staff-view',
			navExclude: '.ptl-nav, nav, #app-navigation',
			listAllow: '.ptl-nav, .ptl-stats, .ptl-cardmeta, .ptl-board, .ptl-list, .ptl-kvrow, .ptl-dev, .ptl-actions',
		});
	});

	test('ATLAS_RENDERED_SURFACE_CONTRACT staff settings view', async ({ page }) => {
		test.skip(!authed, 'no E2E creds');
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
		await page.locator('.ptl-navbtn[data-v="settings"]').click();
		await page.locator('#ptl-body .ptl-card').first().waitFor({ state: 'visible', timeout: 15000 });
		await assertAtlasRenderedSurface(page, {
			content: '#ptl-staff-view',
			navExclude: '.ptl-nav, nav, #app-navigation',
			listAllow: '.ptl-nav, .ptl-stats, .ptl-cardmeta, .ptl-board, .ptl-list, .ptl-kvrow, .ptl-dev, .ptl-actions',
		});
	});
});

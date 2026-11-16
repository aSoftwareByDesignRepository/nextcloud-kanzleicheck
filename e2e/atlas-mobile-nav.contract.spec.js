// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll */
const { test } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	test.skip(!hasCreds(), 'Needs NC_* / E2E_* credentials');
	await login(page, creds());
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto('/apps/kanzleicheck/', { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#kzc-nav-toggle, [data-kzc-nav-toggle]', { timeout: 30000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('#kzc-nav-toggle, [data-kzc-nav-toggle]').first(),
		nav: page.locator('#app-navigation'),
		openClass: /kzc-nav--open/,
	});
});

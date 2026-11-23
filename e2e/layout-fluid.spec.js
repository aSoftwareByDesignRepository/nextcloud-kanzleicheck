// @ts-check
/**
 * Fluid-shell contract — TicketCheck parity: on wide viewports the staff
 * content scales with the page instead of stopping at a centered fixed
 * max-width. If a cap ever returns, it must stay LEFT-aligned — the
 * `margin: 0 auto` centering this contract replaced is the regression.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const authed = hasCreds();

test.describe('fluid content shell', () => {
	test('staff view scales with the page and stays left-aligned', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1600, height: 900 });
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		const view = page.locator('.kzc-view');
		await view.first().waitFor({ state: 'visible', timeout: 20000 });

		const m = await view.first().evaluate((el) => {
			const cs = getComputedStyle(el);
			const r = el.getBoundingClientRect();
			return { maxWidth: cs.maxWidth, marginLeft: cs.marginLeft, marginRight: cs.marginRight, left: r.left, width: r.width };
		});
		expect(m.maxWidth, 'content must not stop at a fixed cap').toBe('none');
		expect(parseFloat(m.marginLeft), 'content must be left-aligned, not centered').toBe(0);
		expect(parseFloat(m.marginRight), 'content must be left-aligned, not centered').toBe(0);
		// sanity: the view actually spans the app content box, not a skinny column
		expect(m.width, 'content should scale with the viewport').toBeGreaterThan(1000);
	});
});

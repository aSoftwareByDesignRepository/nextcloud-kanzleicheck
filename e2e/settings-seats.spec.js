// @ts-check
/**
 * Settings → License → Mobile seats (P6-08): named-seat roster renders for
 * the app admin, assign writes a durable row, revoke removes it — proven
 * through the API reread, not just the DOM.
 *
 * Locale-safe: structural selectors only (ids/classes/dialog roles); uid
 * text matches are locale-independent machine strings.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const TARGET = 'atlas_admin';

async function seatRows(page) {
	const r = await page.request.get(`${APP}/api/portal/seats`);
	const j = await r.json();
	return (j.data && j.data.seats) || [];
}

test.describe('settings mobile seats', () => {
	test.skip(!hasCreds(), 'no creds');

	test('seat card renders, assign + revoke durable', async ({ page }) => {
		await login(page, creds());

		// precondition: target uid holds no seat (API-level cleanup only)
		for (const s of await seatRows(page)) {
			if (s.uid === TARGET) {
				await page.request.delete(`${APP}/api/portal/seats/${s.id}`);
			}
		}

		await page.goto(`${APP}/settings/license`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#seat-q', { timeout: 15000 });
		await page.waitForSelector('#seat-add .kzc-btn--primary', { timeout: 15000 });

		// roster already lists the fixture-admin seat
		await expect(page.locator('.kzc-list')).toBeVisible();
		await expect(page.locator('.kzc-card2', { hasText: 'kzc_atlas_e2e' })).toHaveCount(1);

		// assign via directory picker — never raw uid typing into a field
		await page.fill('#seat-q', TARGET);
		const hit = page.locator('.kzc-picker__results:not([hidden]) .kzc-picker__result').first();
		await expect(hit).toBeVisible({ timeout: 8000 });
		await hit.click();
		await expect(page.locator('.kzc-picker__chips .kzc-chip')).toHaveCount(1);
		await page.locator('#seat-add .kzc-btn--primary').click();

		const row = page.locator('.kzc-card2', { hasText: TARGET });
		await expect(row).toHaveCount(1, { timeout: 10000 });
		// durable postcondition: the API rereads the row, not just the DOM
		expect((await seatRows(page)).some((s) => s.uid === TARGET)).toBe(true);

		// revoke through the same card, confirmed via native dialog
		await row.locator('.kzc-actions .kzc-btn--danger').click();
		const dlg = page.locator('dialog.kzc-dialog');
		await expect(dlg).toBeVisible();
		await dlg.locator('.kzc-btn--primary').click();
		await expect(page.locator('.kzc-card2', { hasText: TARGET })).toHaveCount(0, { timeout: 10000 });
		expect((await seatRows(page)).some((s) => s.uid === TARGET)).toBe(false);
	});
});

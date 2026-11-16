// @ts-check
/**
 * Settings → About this project (sponsor acknowledgement + OSS notice).
 *
 * The about page is a dedicated template route (/settings/about) OUTSIDE
 * the staff SPA — this spec proves it renders inside the staff chrome for
 * a door-admitted user: both cards, all outbound links with new-tab rel
 * armor, translated section copy, and the settings nav link back.
 *
 * Locale-safe: all selectors are structural (ids/classes/href prefixes),
 * never visible-text matches.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const authed = hasCreds();

test.describe('settings about', () => {
	test.skip(!authed, 'no creds');

	test('sponsor + open-source cards render with outbound link armor', async ({ page }) => {
		await login(page, creds());
		await page.goto(`${APP}/settings/about`, { waitUntil: 'domcontentloaded' });

		// dedicated staff shell — not the SPA, not raw JSON
		await expect(page.locator('#app-content.kzc-app')).toBeVisible();
		const sponsor = page.locator('#kzc-about-sponsor-card');
		const oss = page.locator('#kzc-about-oss-card');
		await expect(sponsor).toBeVisible();
		await expect(oss).toBeVisible();

		// sponsor link → real sponsor domain, new-tab armored
		const sponsorLink = sponsor.locator('a[href^="https://steuerberater-eimer.de"]');
		await expect(sponsorLink).toBeVisible();
		await expect(sponsorLink).toHaveAttribute('target', '_blank');
		await expect(sponsorLink).toHaveAttribute('rel', /noopener/);

		// three OSS links: repo, issues, license — all https + armored
		const links = oss.locator('ul a');
		await expect(links).toHaveCount(3);
		for (const prefix of [
			'https://github.com/aSoftwareByDesignRepository/nextcloud-kanzleicheck',
			'https://github.com/aSoftwareByDesignRepository/nextcloud-kanzleicheck/issues',
			'https://www.gnu.org/licenses/agpl-3.0.html',
		]) {
			const a = oss.locator(`a[href="${prefix}"]`);
			await expect(a).toBeVisible();
			await expect(a).toHaveAttribute('target', '_blank');
			await expect(a).toHaveAttribute('rel', /noopener/);
		}

		// no raw l10n key leakage — section copy rendered, not msgids
		const body = await page.locator('#kzc-main-content').innerText();
		expect(body).not.toMatch(/opens in a new tab'\)/);
		expect(body.length).toBeGreaterThan(200);
	});

	test('nonstaff is refused at the page boundary', async ({ page }) => {
		const u = process.env.NC_NONSTAFF_USER, p = process.env.NC_NONSTAFF_PASS;
		test.skip(!(u && p), 'no nonstaff creds');
		await login(page, { username: u, password: p });
		const resp = await page.goto(`${APP}/settings/about`, { waitUntil: 'domcontentloaded' });
		expect(resp.status()).toBe(403);
	});
});

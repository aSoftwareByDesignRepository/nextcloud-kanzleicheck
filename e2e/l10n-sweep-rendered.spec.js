// @ts-check
/**
 * Healed-locale cells must RESOLVE on their owning rendered surfaces —
 * catalog presence alone is not proof (EN-identity fallback is the bug
 * class; a stale l10n/*.js mirror re-serves the English value).
 *
 *   nl    → client detail → "+ Upload link" dialog → label[for=fd-label]
 *           renders "Etiket" (healed msgid "Label").
 *   pt_BR → client detail kv rows → Status label renders "Estado"
 *           (healed msgid "Status"); Settings → license card renders
 *           "Estado:" (healed dep msgid "Status:" — license-page surface).
 *
 * Each test pins the fixture user's `core lang` via occ and restores it in
 * finally. Structural selectors only (ids/classes/for-attrs) — translated
 * strings are assertion targets, never selectors.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const COMPOSE_FILE = path.join(__dirname, '..', '..', '..', 'docker-compose.yml');
const { execSync } = require('child_process');
const { login, hasCreds, creds, acquireLangLock, releaseLangLock } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const authed = hasCreds();

function occ(args) {
	return execSync(
		`docker compose -f ${COMPOSE_FILE} exec -T -u www-data nextcloud php occ ${args}`,
		{ encoding: 'utf8', timeout: 60000 }).trim();
}

async function openFirstClient(page) {
	await page.locator('#app-navigation a.kzc-nav__link[href*="view=orgs"]').click();
	const firstRow = page.locator('#kzc-orglist .kzc-row').first();
	await expect(firstRow).toBeVisible();
	await firstRow.click();
	await expect(page.locator('#kzc-body .kzc-kvrow').first()).toBeVisible();
}

test.describe('l10n healed cells resolve on rendered surfaces', () => {
	test.skip(!authed, 'no creds');

	test('nl: upload-link dialog label renders Etiket', async ({ page }) => {
		// login() pins core/lang=en — the lang lock then serializes OUR
		// mutation window against other mutator specs and other logins.
		await login(page, creds());
		await acquireLangLock();
		const orig = occ('user:setting kzc_atlas_e2e core lang');
		try {
			occ('user:setting kzc_atlas_e2e core lang nl');
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#app-content.kzc-app')).toBeVisible();
			await openFirstClient(page);

			// "+ Upload link" button is the sibling after #kzc-ulinks (the
			// list div may be empty → zero-size → never "visible").
			const ulinkBtn = page.locator('#kzc-ulinks + button');
			await expect(ulinkBtn).toBeVisible();
			await ulinkBtn.click();
			const dlg = page.locator('dialog.kzc-dialog[open]');
			await expect(dlg).toBeVisible();
			const lab = dlg.locator('label.kzc-label[for="fd-label"]');
			await expect(lab).toHaveText('Etiket');
			await expect(lab).not.toHaveText('Label');
			await dlg.press('Escape'); // app's own keydown close path
			await expect(page.locator('dialog.kzc-dialog[open]')).toHaveCount(0);
		} finally {
			occ(`user:setting kzc_atlas_e2e core lang ${orig || 'en'}`);
			await releaseLangLock();
		}
	});

	test('pt_BR: client Status row + settings license card render Estado', async ({ page }) => {
		await login(page, creds());
		await acquireLangLock();
		const orig = occ('user:setting kzc_atlas_e2e core lang');
		try {
			occ('user:setting kzc_atlas_e2e core lang pt_BR');
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#app-content.kzc-app')).toBeVisible();
			await openFirstClient(page);

			// kv rows: Name/Reference/Type/Status/… — Status is index 3.
			const kvLabels = await page.locator('#kzc-body .kzc-kvrow span').allTextContents();
			expect(kvLabels[3]).toBe('Estado');
			expect(kvLabels[3]).not.toBe('Status');

			// Settings → license card: t('Status:') → "Estado:". #l-actions
			// only exists inside the license card after the async fetch.
			await page.locator('#app-navigation a.kzc-nav__link[href*="settings/license"]').click();
			await page.locator('#l-actions').waitFor({ state: 'attached' });
			const text = await page.locator('#kzc-body').innerText();
			expect(text).toContain('Estado:');
			expect(text).not.toContain('Status:');
		} finally {
			occ(`user:setting kzc_atlas_e2e core lang ${orig || 'en'}`);
			await releaseLangLock();
		}
	});
});

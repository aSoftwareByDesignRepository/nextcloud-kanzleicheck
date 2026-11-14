// @ts-check
/**
 * ATLAS kzc-c3 — the offboard response's partialWarning/failed keys must
 * render a visible warning surface (count + next step), never silently.
 * Forced partial-failure via route stub: a live share-revoke failure is not
 * forceable through the API (SyncBindingService::dropShare swallows share
 * errors by design), so the leg fulfils POST …/offboard with the real
 * partial payload and asserts .kzc-warnbar renders with the failed count.
 * Writes a craft PNG to craft/web/.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web');
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

test.describe('staff offboard partial-revoke warning (c3)', () => {
	test('partialWarning response renders warnbar with failed count', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const orgName = `OffboardWarn ${tag}`;
		const api = async (method, u, data) => request.fetch(`${BASE}${APP}${u}`, {
			method, data, headers: { Authorization: auth },
		}).then((r) => r.json());

		const org = (await api('POST', '/api/portal/orgs', { name: orgName, ref: `OW-${tag}`, kind: 'company' })).data.org;
		expect(org.id).toBeGreaterThan(0);

		try {
			await page.route(`**/api/portal/orgs/${org.id}/offboard`, (route) => route.fulfill({
				status: 200,
				contentType: 'application/json',
				json: { success: true, data: { offboarded: true, partialWarning: 'sync_revoke_failed', failed: 2 } },
			}));

			await page.setViewportSize({ width: 1280, height: 800 });
			await login(page, creds());
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
			await page.locator('#kzc-staff-view .kzc-nav').waitFor({ state: 'visible', timeout: 20000 });
			await page.locator('.kzc-navbtn[data-v="orgs"]').click();
			// filter the list to the unique fixture — deterministic wait for the
			// filtered response AND the single-row paint before clicking (the
			// mount fetch may still be in flight; never click mid-race).
			const filtered = page.waitForResponse(
				(r) => r.url().includes('/api/portal/orgs?q=') && r.request().method() === 'GET',
				{ timeout: 20000 },
			);
			await page.locator('#kzc-q').fill(orgName);
			await filtered;
			const rows = page.locator('#kzc-orglist .kzc-row');
			await expect(rows).toHaveCount(1);
			await rows.first().click();
			// the org info card is the one carrying .kzc-kv; its .kzc-actions order
			// is fixed: [appOn toggle, Offboard, Edit details]
			const infoCard = page.locator('#kzc-body .kzc-card:has(.kzc-kv)');
			await infoCard.waitFor({ state: 'visible', timeout: 15000 });
			const actions = infoCard.locator('.kzc-actions .kzc-btn');
			await expect(actions).toHaveCount(3);
			await actions.nth(1).click();
			// confirmDlg → the dialog's primary button carries value="ok"
			const dlg = page.locator('dialog.kzc-dialog');
			await dlg.waitFor({ state: 'visible', timeout: 15000 });
			await dlg.locator('.kzc-btn--primary').click();

			const warn = page.locator('.kzc-warnbar');
			await warn.waitFor({ state: 'visible', timeout: 15000 });
			await expect(warn).toContainText('2');
			await expect(warn).toHaveAttribute('role', 'alert');
			await page.screenshot({ path: path.join(OUT, 'kzc-staff-offboard-warn-light-1280.png'), fullPage: false });
		} finally {
			// teardown: real offboard call sunsets the fixture (page.route stub is
			// browser-context only; the request fixture hits the live endpoint)
			const ob = await request.fetch(`${BASE}${APP}/api/portal/orgs/${org.id}/offboard`, {
				method: 'POST', headers: { Authorization: auth },
			});
			expect([200, 204]).toContain(ob.status());
		}
	});
});

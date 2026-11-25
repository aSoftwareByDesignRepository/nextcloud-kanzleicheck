// @ts-check
/**
 * Staff conversation composer layout — full-width textarea inside a light-grey
 * composer tray, ≥3 rows that scrolls beyond, Enter sends, Shift+Enter
 * newlines without sending.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { spawnSync } = require('child_process');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const COMPOSE_ROOT = path.resolve(__dirname, '../..');
const authed = hasCreds();
const REF = 'COMPOSER-E2E-1';
const ORG_NAME = 'Composer Mandat E2E';
let orgId = 0;
let convId = 0;

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
	return (r.stdout || '').trim();
}

test.describe('staff conversation composer', () => {
	test.beforeAll(() => {
		db(`DELETE FROM oc_kzc_message WHERE conv_id IN (SELECT id FROM oc_kzc_conv WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}'));
DELETE FROM oc_kzc_read WHERE conv_id IN (SELECT id FROM oc_kzc_conv WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}'));
DELETE FROM oc_kzc_conv WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}');
DELETE FROM oc_kzc_org WHERE ref='${REF}';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('${ORG_NAME}','${REF}','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_conv (org_id,state,last_message_at,created_at) SELECT id,'open',NOW(),NOW() FROM oc_kzc_org WHERE ref='${REF}';`);
		orgId = parseInt(db(`SELECT id FROM oc_kzc_org WHERE ref='${REF}'`), 10);
		convId = parseInt(db(`SELECT id FROM oc_kzc_conv WHERE org_id=${orgId}`), 10);
		test.skip(!(orgId > 0 && convId > 0), 'seed failed');
		db(`INSERT INTO oc_kzc_message (conv_id,author,author_kind,body,created_at) VALUES (${convId},'composer-e2e@t.invalid','client','eingang',NOW())`);
	});

	test.afterAll(() => {
		if (convId > 0) {
			db(`DELETE FROM oc_kzc_message WHERE conv_id=${convId};
DELETE FROM oc_kzc_read WHERE conv_id=${convId};
DELETE FROM oc_kzc_conv WHERE id=${convId};`);
		}
		if (orgId > 0) db(`DELETE FROM oc_kzc_org WHERE id=${orgId}`);
	});

	test('textarea spans the grey composer tray, ≥3 rows, scrolls beyond', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await page.goto(`${APP}/?view=inbox&org=${orgId}`, { waitUntil: 'domcontentloaded' });
		const cf = page.locator('#kzc-cf');
		const cm = page.locator('#kzc-cm');
		const btn = cf.locator('button');
		await cm.waitFor({ state: 'visible', timeout: 15000 });

		await expect(cm).toHaveJSProperty('tagName', 'TEXTAREA');
		await expect(cm).toHaveAttribute('rows', '3');
		const [cmBox, cfBox, btnBox] = await Promise.all([cm.boundingBox(), cf.boundingBox(), btn.boundingBox()]);
		if (!cmBox || !cfBox || !btnBox) throw new Error('composer box missing');
		// ≥3 rows visible from the start (rows="3" → ~3 lines + padding)
		expect(cmBox.height).toBeGreaterThanOrEqual(60);
		// field spans the tray: starts at the left padding, button is the only
		// thing right of it (button + gaps + padding stay under ~20%)
		expect(cmBox.x - cfBox.x).toBeLessThanOrEqual(16);
		expect(cmBox.width / cfBox.width).toBeGreaterThan(0.80);
		expect(cfBox.x + cfBox.width - (btnBox.x + btnBox.width)).toBeLessThan(16);

		// composer tray carries a light-grey surface (theme-safe color-mix tint,
		// near-equal channels, clearly lighter than ink). Chromium serializes
		// color-mix() as `color(srgb r g b / a)` with 0..1 channels; accept the
		// legacy rgb() form too.
		const bg = await cf.evaluate((el) => getComputedStyle(el).backgroundColor);
		let ch, alpha;
		const srgb = bg.match(/color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\)/);
		const rgb = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
		if (srgb) { ch = [+srgb[1], +srgb[2], +srgb[3]]; alpha = srgb[4]; }
		else if (rgb) { ch = [+rgb[1] / 255, +rgb[2] / 255, +rgb[3] / 255]; alpha = rgb[4]; }
		else throw new Error(`composer bg not a color: ${bg}`);
		expect(alpha === undefined || +alpha > 0).toBe(true);
		expect(Math.max(...ch) - Math.min(...ch)).toBeLessThan(0.05);
		expect(Math.min(...ch)).toBeGreaterThan(0.75);

		// scrollable past the visible rows
		await cm.fill(['l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8'].join('\n'));
		expect(await cm.evaluate((el) => el.scrollHeight > el.clientHeight)).toBe(true);
	});

	test('Enter sends, Shift+Enter adds a newline without sending', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await page.goto(`${APP}/?view=inbox&org=${orgId}`, { waitUntil: 'domcontentloaded' });
		const cm = page.locator('#kzc-cm');
		await cm.waitFor({ state: 'visible', timeout: 15000 });

		await cm.fill('zeile eins\nzeile zwei');
		await cm.press('Enter');
		await page.locator('.kzc-msg', { hasText: 'zeile eins' }).waitFor({ state: 'visible', timeout: 15000 });
		await expect(cm).toHaveValue('');

		const before = await page.locator('.kzc-msg').count();
		await cm.fill('draft');
		await cm.press('Shift+Enter');
		await expect(cm).toHaveValue('draft\n');
		await page.waitForTimeout(800);
		expect(await page.locator('.kzc-msg').count()).toBe(before);
	});
});

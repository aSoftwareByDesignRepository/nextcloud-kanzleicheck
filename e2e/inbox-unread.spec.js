// @ts-check
/**
 * Staff unread badge lifecycle — a conversation row's badge counts client
 * messages the staff user has not read; opening the conversation advances
 * the server read marker and the badge is gone on return to the inbox.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { spawnSync } = require('child_process');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const COMPOSE_ROOT = path.resolve(__dirname, '../..');
const authed = hasCreds();
// Unique per run — two concurrent suites on the shared dev DB must not
// delete each other's fixture rows via the same ref (farm runs overlap).
const TAG = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
const REF = 'UNREAD-E2E-1-' + TAG;
const ORG_NAME = 'Unread Mandat E2E ' + TAG;
let orgId = 0;
let convId = 0;

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
	return (r.stdout || '').trim();
}

test.describe('staff inbox unread badge', () => {
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
		db(`INSERT INTO oc_kzc_message (conv_id,author,author_kind,body,created_at) VALUES (${convId},'unread-e2e@t.invalid','client','bitte um rückmeldung',NOW())`);
	});

	test.afterAll(() => {
		if (convId > 0) {
			db(`DELETE FROM oc_kzc_message WHERE conv_id=${convId};
DELETE FROM oc_kzc_read WHERE conv_id=${convId};
DELETE FROM oc_kzc_conv WHERE id=${convId};`);
		}
		if (orgId > 0) db(`DELETE FROM oc_kzc_org WHERE id=${orgId}`);
	});

	test('badge shows unread count then clears after opening the chat', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await page.goto(`${APP}/?view=inbox`, { waitUntil: 'domcontentloaded' });
		const row = page.locator('.kzc-row', { hasText: ORG_NAME });
		await row.waitFor({ state: 'visible', timeout: 15000 });
		await expect(row.locator('.kzc-badge')).toHaveText('1');
		// unread row: bold title + emphasized preview
		await expect(row.locator('.kzc-row__title')).toHaveCSS('font-weight', '700');
		await expect(row.locator('.kzc-row__sub')).toHaveCSS('font-weight', '600');
		await row.click();
		await page.locator('#kzc-msgs .kzc-msg').waitFor({ state: 'visible', timeout: 15000 });
		await page.getByRole('button', { name: 'All messages' }).click();
		const back = page.locator('.kzc-row', { hasText: ORG_NAME });
		await back.waitFor({ state: 'visible', timeout: 15000 });
		await expect(back.locator('.kzc-badge')).toHaveCount(0);
		// read row: title + preview back to regular weight
		await expect(back.locator('.kzc-row__title')).toHaveCSS('font-weight', '400');
		await expect(back.locator('.kzc-row__sub')).toHaveCSS('font-weight', '400');
	});
});

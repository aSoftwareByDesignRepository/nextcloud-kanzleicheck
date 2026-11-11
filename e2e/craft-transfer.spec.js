// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/portalcheck/craft/web/.
 * Flow: settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 * Recaptured after the positional-placeholder t() fix — the rendered
 * strings must show values, never %d/%s/%1$s residue.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const PASS = 'atlas-e2e-passphrase-2026';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/portalcheck/craft/web');
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

function occ(args) {
	return execSync(
		`docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml exec -T -u www-data nextcloud php occ ${args}`,
		{ encoding: 'utf8', timeout: 60000 }).trim();
}
function db(sql) {
	return execSync(
		`docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e "${sql.replace(/"/g, '\\"')}"`,
		{ encoding: 'utf8', timeout: 30000 }).trim();
}
async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

test.describe('craft transfer', () => {
	test.skip(!authed, 'no creds');

	test('staff transfer surface + report/progress dialogs 1280/375', async ({ page, request }) => {
		test.setTimeout(240_000);
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');

		const exp = await request.post(`${BASE}${APP}/api/admin/transfer/export`, {
			headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, data: { passphrase: PASS },
		});
		expect(exp.status()).toBe(200);
		const bytes = await exp.body();
		expect(bytes.length).toBeGreaterThan(200);

		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, creds());
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#ptl-transfer-export-card')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'ptl-staff-transfer-light-1280');

		// uniform file error — wrong passphrase on a real container
		await page.locator('#ptl-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#ptl-transfer-import-passphrase').fill('wrong-passphrase-000');
		await expect(page.locator('#ptl-transfer-result-live')).not.toBeEmpty({ timeout: 30000 });
		await shot(page, 'ptl-staff-transfer-fileerror-light-1280');

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#ptl-transfer-import-passphrase').fill(PASS);
		const report = page.locator('#ptl-transfer-report');
		await expect(report).toBeVisible({ timeout: 30000 });
		await expect(page.locator('#ptl-transfer-report-verdict')).toContainText(/\d/);
		await shot(page, 'ptl-staff-transfer-report-dialog-light-1280');

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#ptl-transfer-confirm').click();
		const progress = page.locator('#ptl-transfer-progress');
		await expect(progress).toBeVisible({ timeout: 10000 });
		await expect(page.locator('#ptl-transfer-progress-text')).not.toBeEmpty();
		await shot(page, 'ptl-staff-transfer-progress-light-1280');

		const jobId = db("SELECT id FROM oc_jobs WHERE class LIKE '%TransferApplyJob%' ORDER BY id DESC LIMIT 1");
		expect(jobId).toMatch(/^\d+$/);
		const before = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } });
		const priorReceiptId = (((await before.json()).data || {}).lastReceipt || {}).id || '';
		occ(`background-job:execute ${jobId}`);

		let receipt = {};
		for (let i = 0; i < 60; i++) {
			const s = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } });
			receipt = (((await s.json()).data || {}).lastReceipt) || {};
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break;
			await new Promise((r) => setTimeout(r, 1000));
		}
		expect(receipt.result).toBe('applied');

		// page reloads itself on done — settle, then capture post-apply state
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('#ptl-transfer-last')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'ptl-staff-transfer-after-apply-light-1280');
	});

	test('staff transfer surface mobile 375', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await login(page, creds());
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#ptl-transfer-export-card')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'ptl-staff-transfer-light-375');
	});
});

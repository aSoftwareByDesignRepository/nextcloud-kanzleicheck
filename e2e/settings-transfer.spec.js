// @ts-check
/**
 * ATLAS transfer surface — config-transfer settings UI.
 * Legs: admin cards render; nonstaff refused; passphrase strength;
 * uniform file-error message; real export→preview→report dialog
 * (verdict + counters); Escape closes the dialog; apply queues the
 * job → receipt applied; Last transfer line updates.
 * Structural selectors only (locale-safe).
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const COMPOSE_FILE = path.join(__dirname, '..', '..', '..', 'docker-compose.yml');
const { execSync } = require('child_process');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');
const { acquireTransferApplyLock } = require('../tests/e2e/helpers/transfer-apply-lock.js');

const APP = '/index.php/apps/kanzleicheck';
const PASS = 'atlas-e2e-passphrase-2026';
const authed = hasCreds();

function occ(args) {
	return execSync(
		`docker compose -f ${COMPOSE_FILE} exec -T -u www-data nextcloud php occ ${args}`,
		{ encoding: 'utf8', timeout: 60000 }).trim();
}
function db(sql) {
	return execSync(
		`docker compose -f ${COMPOSE_FILE} exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e "${sql.replace(/"/g, '\\"')}"`,
		{ encoding: 'utf8', timeout: 30000 }).trim();
}

test.describe('settings transfer surface', () => {
	test.skip(!authed, 'no creds');

	test('admin sees export/import cards; nonstaff gets no transfer section', async ({ page, request }) => {
		const { username, password } = creds();
		await login(page, creds());
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#kzc-transfer-export-card')).toBeVisible({ timeout: 20000 });
		await expect(page.locator('#kzc-transfer-import-card')).toBeVisible();
		await expect(page.locator('#kzc-transfer-passphrase')).toBeVisible();
		await expect(page.locator('#kzc-transfer-file')).toBeAttached();
		await expect(page.locator('#kzc-transfer-import-passphrase')).toBeVisible();
		// nonstaff API path — the section refuses at the page boundary too
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const r = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } });
		expect(r.status()).toBe(200);
	});

	test('passphrase strength + uniform file error + preview→apply merge + Escape', async ({ page, request }) => {
		test.setTimeout(240_000); // real Argon2id previews + a full apply (≈45s job)
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');

		// export a real container via the API (bytes feed the file input)
		const exp = await request.post(`${BASE}${APP}/api/admin/transfer/export`, {
			headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, data: { passphrase: PASS },
		});
		expect(exp.status()).toBe(200);
		const bytes = await exp.body();
		expect(bytes.length).toBeGreaterThan(200);

		await login(page, creds());
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#kzc-transfer-export-card')).toBeVisible({ timeout: 20000 });

		// strength meter reacts
		const strength = page.locator('#kzc-transfer-passphrase-strength');
		await page.locator('#kzc-transfer-passphrase').fill('abc');
		await expect(strength).not.toBeEmpty();
		await page.locator('#kzc-transfer-passphrase').fill(PASS + PASS);
		await expect(strength).not.toBeEmpty();

		// wrong passphrase on the real file → uniform file-error, no oracle
		await page.locator('#kzc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#kzc-transfer-import-passphrase').fill('wrong-passphrase-000');
		// preview auto-runs on file change + passphrase input (debounced 500ms);
		// a rejected preview surfaces via msg() → #kzc-transfer-result-live and
		// never opens the report dialog (#kzc-transfer-import-status is only
		// the in-flight hint — it clears on settle, do not assert it).
		await expect(page.locator('#kzc-transfer-result-live')).not.toBeEmpty({ timeout: 30000 });
		await expect(page.locator('#kzc-transfer-report')).not.toBeVisible();

		// correct passphrase → preview → report dialog
		await page.locator('#kzc-transfer-import-passphrase').fill(PASS);
		const report = page.locator('#kzc-transfer-report');
		await expect(report).toBeVisible({ timeout: 30000 });
		await expect(page.locator('#kzc-transfer-report-verdict')).toBeVisible();
		await expect(page.locator('#kzc-transfer-report-file')).not.toBeEmpty();
		await expect(page.locator('#kzc-transfer-report-groups')).not.toBeEmpty();

		// rendered-placeholder contract: no vsprintf residue (%d, %s, %1$s)
		// may reach the admin — window.t expands {name} tokens only, so the
		// t() helper interpolates positional params itself. Locale-safe:
		// assert absence of residue + presence of interpolated digits, never
		// English copy.
		const residue = /%(?:\d+\$)?[sd]/;
		await expect(page.locator('#kzc-transfer-report-verdict')).not.toContainText(residue);
		await expect(page.locator('#kzc-transfer-report-verdict')).toContainText(/\d/);
		await expect(page.locator('#kzc-transfer-report-file')).not.toContainText(residue);
		await expect(page.locator('#kzc-transfer-report-file')).toContainText(/\d/);
		await expect(page.locator('#kzc-transfer-report-groups')).not.toContainText(residue);
		await expect(page.locator('#kzc-transfer-report-groups')).toContainText(/\d/);

		// Escape closes (dialog-level receiver, host handlers can't swallow it)
		await page.keyboard.press('Escape');
		await expect(report).not.toBeVisible();

		// re-preview → apply merge → progress → receipt (input events re-trigger)
		await page.locator('#kzc-transfer-import-passphrase').fill(PASS + ' ');
		await page.locator('#kzc-transfer-import-passphrase').fill(PASS);
		await expect(report).toBeVisible({ timeout: 30000 });
		// Serialize the mutating leg: apply holds an exclusive server mutex
		// over a single job/receipt slot — craft-transfer's apply leg would
		// race it in a parallel worker (loser dies TRANSFER_IN_PROGRESS and
		// its progress dialog flash-closes before any poll sees it).
		const releaseApplyLock = await acquireTransferApplyLock();
		try {
			await page.locator('#kzc-transfer-confirm').click();
			const progress = page.locator('#kzc-transfer-progress');
			await expect(progress).toBeVisible({ timeout: 30000 });
			// progress phase line interpolates 'Phase: %s' — no raw % residue
			await expect(page.locator('#kzc-transfer-progress-text')).not.toContainText(/%(?:\d+\$)?[sd]/);

			const jobId = db("SELECT id FROM oc_jobs WHERE class LIKE '%TransferApplyJob%' ORDER BY id DESC LIMIT 1");
			expect(jobId).toMatch(/^\d+$/);
			// receipt id of the PREVIOUS run — the poll must wait for a NEW one,
			// otherwise it latches onto the stale 'applied' receipt immediately.
			const before = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } });
			const priorReceiptId = (((await before.json()).data || {}).lastReceipt || {}).id || '';
			try {
				occ(`background-job:execute ${jobId}`);
			} catch {
				// nextcloud-cron drains oc_jobs concurrently — the queued
				// TransferApplyJob may already be gone by the time occ runs.
				// The receipt poll below still proves the apply ran.
			}

			// poll status until THIS apply's receipt lands
			let receipt = {};
			for (let i = 0; i < 60; i++) {
				const s = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } });
				const d = (await s.json()).data || {};
				receipt = d.lastReceipt || {};
				if (receipt.id && receipt.id !== priorReceiptId
					&& (receipt.result === 'applied' || receipt.result === 'failed')) break;
				await new Promise((r) => setTimeout(r, 1000));
			}
			expect(receipt.id).not.toBe(priorReceiptId);
			expect(receipt.result).toBe('applied');
			expect(receipt.mode).toBe('merge');
		} finally {
			releaseApplyLock();
		}
	});
});

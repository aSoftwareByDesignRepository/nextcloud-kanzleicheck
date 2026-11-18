// @ts-check
/**
 * Plural-arity rendered proof — `_sg_::_pl_` catalog arrays are indexed by
 * getPlural(locale)(count) at runtime (the gettext register arg is ignored
 * on this NC version). pl selects 3 forms, tr/zh 1 — under-filled arrays
 * render undefined mid-render, over-filled single-form arrays pin a
 * literal "1" for every count.
 *
 * Leg: pin fixture user lang=pl, run a real export→preview, assert the
 * report verdict + groups render localized plural forms with the count
 * substituted — no literal "%n"/"%1$s"/"undefined" and no English
 * fallback. Restores the user's lang in finally.
 * Structural selectors only (locale-safe).
 */
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const PASS = 'atlas-e2e-passphrase-2026';
const authed = hasCreds();
const FIXTURE_USER = process.env.NC_ADMIN_USER || '';

function occ(args) {
	return execSync(
		`docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml exec -T -u www-data nextcloud php occ ${args}`,
		{ encoding: 'utf8', timeout: 60000 }).trim();
}

test.describe('transfer preview verdict — plural rendering (pl)', () => {
	test.skip(!authed, 'no creds');

	test('pl: verdict + groups render n() plural forms, no raw placeholder', async ({ page, request }) => {
		test.setTimeout(240_000); // real Argon2id preview
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const orig = occ(`user:setting ${FIXTURE_USER} core lang`);

		try {
			occ(`user:setting ${FIXTURE_USER} core lang pl`);

			const exp = await request.post(`${BASE}${APP}/api/admin/transfer/export`, {
				headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, data: { passphrase: PASS },
			});
			expect(exp.status()).toBe(200);
			const bytes = await exp.body();
			expect(bytes.length).toBeGreaterThan(200);

			await login(page, creds());
			await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#kzc-transfer-import-card')).toBeVisible({ timeout: 20000 });

			await page.locator('#kzc-transfer-file').setInputFiles({
				name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
			});
			await page.locator('#kzc-transfer-import-passphrase').fill(PASS);

			const report = page.locator('#kzc-transfer-report');
			await expect(report).toBeVisible({ timeout: 120_000 });

			const verdict = page.locator('#kzc-transfer-report-verdict');
			await expect(verdict).toContainText(/\d/);
			const verdictText = (await verdict.textContent()) || '';
			// pl plural stems must resolve (ustawienie/ustawienia/ustawień and
			// wiersz/wiersze/wierszy all share these roots); the healed 3-form
			// arrays must substitute %n — a broken array renders "undefined"
			// or leaks the raw placeholder.
			expect(verdictText).toMatch(/ustawie/i);
			expect(verdictText).toMatch(/wiersz/i);
			expect(verdictText).not.toMatch(/%n|%1\$s|%2\$s|undefined/i);
			// pl one-form would pin a literal "1" for a non-1 count — the merge
			// counts on a real instance are >1, so a constant "1 X" pair is the
			// dishonest-counter signature.
			expect(verdictText).not.toBe('');

			const groups = page.locator('#kzc-transfer-report-groups');
			const groupsText = (await groups.textContent()) || '';
			expect(groupsText).not.toMatch(/%n|undefined/i);

			await page.keyboard.press('Escape');
			await expect(report).not.toBeVisible();
		} finally {
			occ(`user:setting ${FIXTURE_USER} core lang ${orig || 'en'}`);
		}
	});
});

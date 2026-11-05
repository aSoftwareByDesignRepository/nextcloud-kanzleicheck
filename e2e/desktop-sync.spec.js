// @ts-check
/**
 * Desktop-Sync staff UI (nc-user-binding §7) — one section per org detail,
 * one setup dialog (people-search picker + suggestion), state chips, repair
 * CTA, toggle-off confirm, proactive open-door notice. The setup flow is
 * driven against the real instance: ptl_atlas_nonstaff has NO account email,
 * so the in-dialog mismatch confirm gate is exercised for real.
 *
 * Writes craft PNGs to .cursor/atlas-farm-v3/artifacts/portalcheck/craft/web/.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/portalcheck/craft/web');
const COMPOSE_ROOT = path.resolve(__dirname, '../../..');
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
	return (r.stdout || '').trim();
}

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

test.describe('staff Desktop-Sync (nc-user-binding)', () => {
	test('setup dialog binds+enables, chip shows state, toggle revokes — incl. mismatch confirm gate', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const email = `ptl-syncui-${tag}@test.invalid`;
		const orgName = `SyncUI Mandat ${tag}`;
		const api = async (method, u, data) => request.fetch(`${BASE}${APP}${u}`, {
			method, data, headers: { Authorization: auth },
		}).then((r) => r.json());

		const origAdmins = db("SELECT configvalue FROM oc_appconfig WHERE appid='portalcheck' AND configkey='app_admins'");
		const admins = [...new Set([...(JSON.parse(origAdmins || '[]')), username])];
		db(`UPDATE oc_appconfig SET configvalue='${JSON.stringify(admins)}' WHERE appid='portalcheck' AND configkey='app_admins'`);

		let orgId = 0;
		let identityId = 0;
		try {
			const org = await api('POST', '/api/portal/orgs', { name: orgName, ref: `SYN-${tag}`, kind: 'company' });
			orgId = org.data.org.id;
			expect(orgId).toBeGreaterThan(0);
			const m = await api('POST', `/api/portal/orgs/${orgId}/members`, { email, displayName: 'Sync UI Client' });
			expect(m.success).toBeTruthy();
			identityId = m.data?.identityId ?? Number(db(`SELECT identity_id FROM oc_ptl_member WHERE org_id=${orgId} ORDER BY id DESC LIMIT 1`));
			expect(identityId).toBeGreaterThan(0);

			await page.setViewportSize({ width: 1280, height: 800 });
			await login(page, { username, password });
			await page.goto(`${APP}/?view=orgs`, { waitUntil: 'domcontentloaded' });
			await page.locator('#ptl-q').fill(orgName);
			const row0 = page.locator('#ptl-orglist .ptl-row', { hasText: orgName });
			await row0.waitFor({ state: 'visible', timeout: 20000 });
			await row0.click();
			await page.locator('h2:has-text("Desktop sync")').waitFor({ state: 'visible', timeout: 20000 });

			/* ── teacher line + member row with Set up ── */
			await expect(page.locator('.ptl-sync-list')).toBeVisible();
			const row = page.locator('.ptl-sync-row', { hasText: 'Sync UI Client' });
			await expect(row).toBeVisible();
			await shot(page, 'ptl-staff-sync-section-1280');
			// DOM click — the row sits below the fold inside NC's #app-content
			// scroller, which confuses Playwright's viewport math (the element
			// is genuinely visible + enabled for a real user).
			await row.locator('button:has-text("Set up")').evaluate((el) => el.click());

			/* ── one dialog: picker, perms radios, mismatch confirm in-dialog ── */
			const dlg = page.locator('dialog.ptl-dialog');
			await dlg.waitFor({ state: 'visible', timeout: 15000 });
			await expect(dlg.locator('h2')).toContainText('desktop sync', { ignoreCase: true });
			await expect(dlg.locator('text=Read & write')).toBeVisible();
			await expect(dlg.locator('text=Read only')).toBeVisible();
			await dlg.locator('#sd-q').fill('ptl_atlas');
			const hit = dlg.locator('#sd-hits button', { hasText: 'ptl_atlas_nonstaff' });
			await hit.waitFor({ state: 'visible', timeout: 15000 });
			await expect(hit).toBeEnabled(); // non-staff user is selectable
			await hit.click();
			await shot(page, 'ptl-staff-sync-dialog-picked');
			await dlg.locator('.ptl-btn--primary:has-text("Link")').click();

			/* mismatch confirm INSIDE the same dialog (empty NC email vs identity mail) */
			const confirm = page.locator('dialog.ptl-dialog').last();
			await confirm.waitFor({ state: 'visible', timeout: 20000 });
			await confirm.locator('button', { hasText: /confirm|bestät|ok|yes|ja/i }).click();

			/* chip renders the binding state (active or pending-accept) +
			   the durable mismatch flag chip ('e-mail differs') */
			const chip = row.locator('.ptl-chip').first();
			await chip.waitFor({ state: 'visible', timeout: 20000 });
			await expect(row.locator('.ptl-chip', { hasText: 'e-mail differs' })).toBeVisible();
			await shot(page, 'ptl-staff-sync-chip-active');
			const bid = Number(db(`SELECT id FROM oc_ptl_sync WHERE org_id=${orgId} ORDER BY id DESC LIMIT 1`));
			expect(bid).toBeGreaterThan(0);
			const shareRows = db(`SELECT COUNT(*) FROM oc_share WHERE share_with='ptl_atlas_nonstaff' AND permissions=15`);
			expect(shareRows).toBe('1');

			/* ── toggle off → confirm → revoked, share row deleted ── */
			await row.locator('button:has-text("Off")').evaluate((el) => el.click());
			const off = page.locator('dialog.ptl-dialog').last();
			await off.waitFor({ state: 'visible', timeout: 15000 });
			await off.locator('button', { hasText: /confirm|bestätigen/i }).last().click();
			await page.waitForTimeout(1500);
			await expect(row.locator('button:has-text("Set up")')).toBeVisible();
			expect(db(`SELECT COUNT(*) FROM oc_share WHERE share_with='ptl_atlas_nonstaff'`)).toBe('0');
			expect(db(`SELECT state FROM oc_ptl_sync WHERE id=${bid}`)).toBe('revoked');
		} finally {
			/* revoke any leftover share/binding + restore door + drop fixture rows */
			try { if (orgId) await api('POST', `/api/portal/orgs/${orgId}/offboard`, {}); } catch { /* best effort */ }
			for (const sid of (db(`SELECT GROUP_CONCAT(share_id) FROM oc_ptl_sync WHERE org_id=${orgId}`) || '').split(',')) {
				if (sid && sid !== '0') db(`DELETE FROM oc_share WHERE id=${Number(sid)}`);
			}
			db(`DELETE FROM oc_ptl_sync WHERE org_id=${orgId};
DELETE FROM oc_ptl_member WHERE org_id=${orgId};
DELETE FROM oc_ptl_identity WHERE email='${email}';
DELETE FROM oc_ptl_org WHERE ref='SYN-${tag}';
UPDATE oc_appconfig SET configvalue='${origAdmins.replace(/'/g, "''")}' WHERE appid='portalcheck' AND configkey='app_admins';`);
		}
	});
});

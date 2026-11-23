// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web/.
 * Flow: settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 * Recaptured after the positional-placeholder t() fix — the rendered
 * strings must show values, never %d/%s/%1$s residue.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const COMPOSE_FILE = path.join(__dirname, '..', '..', '..', 'docker-compose.yml');
const { execSync } = require('child_process');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');
const { acquireTransferApplyLock } = require('../tests/e2e/helpers/transfer-apply-lock.js');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract.js');

const APP = '/index.php/apps/kanzleicheck';
const PASS = 'atlas-e2e-passphrase-2026';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/kanzleicheck/craft/web');
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

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
async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

/** overlay_toast_button_armor_gap: the toast dismiss is a bare
 * <button class="kzc-toast__close"> mounted on document.body inside
 * #kzc-toasts — NC core's button repaint (width:130px + pale fill +
 * border at 0-1-1, margin-inline-start:0 at 0-2-1) must not reach it.
 * Assert the painted geometry is the declared transparent ~44px
 * icon-close docked at the toast's inline-end on one row — not the
 * collapsed ~128px pale core-skin block wrapped to a second row.
 * Donor shape: whistlecheck assertToastCloseArmored (wc-vis-r13-01). */
async function assertToastCloseArmored(page) {
	const close = page.locator('.kzc-toast.kzc-toast--error .kzc-toast__close').first();
	await expect(close).toBeVisible();
	const m = await close.evaluate((el) => {
		const r = el.getBoundingClientRect();
		const cs = getComputedStyle(el);
		const host = el.closest('.kzc-toast').getBoundingClientRect();
		return {
			x: r.x, y: r.y, w: r.width, h: r.height,
			hostX: host.x, hostY: host.y, hostW: host.width, hostH: host.height,
			vpH: window.innerHeight,
			bg: cs.backgroundColor, bw: cs.borderWidth,
		};
	});
	expect(m.bg, 'dismiss keeps transparent background (no pale core skin)')
		.toBe('rgba(0, 0, 0, 0)');
	expect(m.bw, 'dismiss keeps border:0').toBe('0px');
	expect(m.w, 'dismiss ≈44px — core width:130px repaint would blow it out')
		.toBeLessThanOrEqual(56);
	expect(m.w).toBeGreaterThanOrEqual(40);
	expect(m.h).toBeGreaterThanOrEqual(40);
	expect(m.h).toBeLessThanOrEqual(64);
	// inline-end dock + single row: glyph sits in the toast's trailing half
	// and inside its height (the collapsed block wrapped to a second row)
	expect(m.x + m.w / 2).toBeGreaterThan(m.hostX + m.hostW / 2);
	expect(m.y + m.h).toBeLessThanOrEqual(m.hostY + m.hostH + 1);
	// the stack clears the bottom viewport edge (safe-area floor)
	expect(m.hostY + m.hostH).toBeLessThanOrEqual(m.vpH + 1);
}

/** native_dialog_margin_reset_uncentered: NC core's reset stylesheet
 * zeroes the UA 'margin:auto' that centers a top-layer <dialog>, so the
 * pack re-declares it on .kzc-dialog — and the ≤480px media query
 * re-asserts margin:0 for the full-bleed sheet (donor selector is
 * element+class dialog.kzc-dialog at 0,1,1). Assert painted geometry,
 * not only the declaration: ≥481px the dialog must sit centered with
 * symmetric margins, ≤480px it must be a full-bleed sheet. */
async function assertDialogGeometry(dlg, mode) {
	const g = await dlg.evaluate((d) => {
		const cs = getComputedStyle(d);
		const r = d.getBoundingClientRect();
		let declared = false;
		for (const sheet of document.styleSheets) {
			try {
				for (const rule of sheet.cssRules) {
					if ((rule.selectorText || '').includes('.kzc-dialog')
						&& /(^|[;\s])margin:\s*auto/.test(rule.cssText || '')) declared = true;
				}
			} catch { /* cross-origin sheet */ }
		}
		return {
			vw: window.innerWidth, vh: window.innerHeight,
			left: r.left, right: r.right, top: r.top,
			width: r.width, height: r.height,
			marginLeft: cs.marginLeft, marginRight: cs.marginRight,
			declared,
		};
	});
	expect(g.declared, 'no live stylesheet declares margin:auto on .kzc-dialog — core reset anchors the dialog top-left').toBe(true);
	if (mode === 'centered') {
		const hGap = g.vw - g.width;
		const vGap = g.vh - g.height;
		expect(Math.abs(g.left - hGap / 2), `dialog left ${Math.round(g.left)} vs symmetric margin ${Math.round(hGap / 2)}`).toBeLessThanOrEqual(2);
		expect(Math.abs((g.vw - g.right) - hGap / 2)).toBeLessThanOrEqual(2);
		expect(Math.abs(g.top - vGap / 2), `dialog top ${Math.round(g.top)} vs symmetric margin ${Math.round(vGap / 2)}`).toBeLessThanOrEqual(4);
		expect(g.left, 'dialog anchored at viewport left edge — core margin reset won').toBeGreaterThan(100);
	} else {
		expect(g.left, `full-bleed sheet left ${Math.round(g.left)} ≠ 0`).toBeLessThanOrEqual(1);
		expect(Math.abs(g.width - g.vw), `full-bleed sheet width ${Math.round(g.width)} ≠ viewport ${g.vw}`).toBeLessThanOrEqual(2);
		expect(g.marginLeft, 'full-bleed sheet keeps a side margin').toBe('0px');
		expect(g.marginRight).toBe('0px');
	}
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
		await expect(page.locator('#kzc-transfer-export-card')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'kzc-staff-transfer-light-1280');

		// uniform file error — wrong passphrase on a real container
		await page.locator('#kzc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#kzc-transfer-import-passphrase').fill('wrong-passphrase-000');
		// dead_platform_toast_api: the failure must reach a VISIBLE app-owned
		// toast, not only the visually-hidden live region (OC.Notification is
		// gone from NC≥31 bundles — the old bridge silently no-ops there).
		await expect(page.locator('.kzc-toast--error').first()).toBeVisible({ timeout: 30000 });
		await assertToastCloseArmored(page);
		await expect(page.locator('#kzc-transfer-result-live')).not.toBeEmpty();
		await shot(page, 'kzc-staff-transfer-fileerror-light-1280');

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#kzc-transfer-import-passphrase').fill(PASS);
		const report = page.locator('#kzc-transfer-report');
		await expect(report).toBeVisible({ timeout: 30000 });
		await expect(page.locator('#kzc-transfer-report-verdict')).toContainText(/\d/);
		await assertDialogGeometry(report, 'centered');
		await shot(page, 'kzc-staff-transfer-report-dialog-light-1280');

		// apply → progress dialog stays open while the job waits queued.
		// Serialize the mutating leg: apply holds an exclusive server mutex
		// over a single job/receipt slot — settings-transfer's apply leg
		// would race it in a parallel worker (loser dies TRANSFER_IN_PROGRESS
		// and its progress dialog flash-closes before any poll sees it).
		const releaseApplyLock = await acquireTransferApplyLock();
		try {
			await page.locator('#kzc-transfer-confirm').click();
			const progress = page.locator('#kzc-transfer-progress');
			await expect(progress).toBeVisible({ timeout: 30000 });
			await expect(page.locator('#kzc-transfer-progress-text')).not.toBeEmpty();
			await shot(page, 'kzc-staff-transfer-progress-light-1280');

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

			// The page's own 1.5s status poll defers location.reload() 2.5s
			// after done — waitForLoadState alone races it and shoots the
			// pre-reload DOM (stale progress dialog + old receipt). Wait for
			// the receipt row to carry THIS apply's `at` stamp instead; only
			// the reloaded DOM renders it (byte-identical craft defect).
			await expect(page.locator('#kzc-transfer-last'))
				.toContainText(String(receipt.at), { timeout: 30000 });
			// …and the receipt sits inside the import card below the 800px
			// fold — scroll it in-frame or the shot repaints the pre-apply
			// export card and fakes "after apply".
			await page.locator('#kzc-transfer-last').scrollIntoViewIfNeeded();
			await page.waitForTimeout(600);
			await shot(page, 'kzc-staff-transfer-after-apply-light-1280');
		} finally {
			releaseApplyLock();
		}
	});

	test('staff transfer surface mobile 375', async ({ page, request }) => {
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');

		await page.setViewportSize({ width: 375, height: 812 });
		await login(page, creds());
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#kzc-transfer-export-card')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'kzc-staff-transfer-light-375');

		// dead_platform_toast_api at narrow width: a failed preview must paint
		// a visible .kzc-toast--error (not only the hidden live region).
		const exp = await request.post(`${BASE}${APP}/api/admin/transfer/export`, {
			headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, data: { passphrase: PASS },
		});
		expect(exp.status()).toBe(200);
		const bytes = await exp.body();
		await page.locator('#kzc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#kzc-transfer-import-passphrase').fill('wrong-passphrase-000');
		await expect(page.locator('.kzc-toast--error').first()).toBeVisible({ timeout: 30000 });
		await assertToastCloseArmored(page);
		await expect(page.locator('#kzc-transfer-result-live')).not.toBeEmpty();
		await shot(page, 'kzc-staff-transfer-fileerror-light-375');
	});

	test('staff transfer replace-confirm token row wide (cramped_form_controls)', async ({ page, request }) => {
		test.setTimeout(180_000);
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
		await expect(page.locator('#kzc-transfer-export-card')).toBeVisible({ timeout: 20000 });

		// Full-form pinned-control sweep (cramped_form_controls): every text
		// control on the transfer surface must escape the NC-core 130px pin.
		await assertAtlasRenderedSurface(page, {
			content: '#kzc-main-content',
			navExclude: '.kzc-tabbar, nav',
		});

		// Replace mode -> real preview -> report dialog footer shows the
		// token row. Preview needs no maintenance mode (a warning callout
		// renders instead) — the dialog must not be applied.
		await page.locator('#kzc-transfer-import-form input[type="radio"][value="replace"]').check();
		await page.locator('#kzc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#kzc-transfer-import-passphrase').fill(PASS);
		const report = page.locator('#kzc-transfer-report');
		await expect(report).toBeVisible({ timeout: 30000 });
		const tokenRow = page.locator('#kzc-transfer-replace-confirm');
		await expect(tokenRow).toBeVisible();
		const token = page.locator('#kzc-transfer-replace-token');
		await expect(token).toBeVisible();

		// cramped_form_controls fix live: the input escapes the core 130px pin.
		const w = await token.evaluate((el) => el.getBoundingClientRect().width);
		expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140);

		// required_field_or_step_undiscoverable (visual r29): the report
		// dialog's footer — token row + Cancel + Apply import — must paint
		// INSIDE the dialog's client box at every viewport. The shell must
		// never be the scroller: .kzc-dialog__body absorbs the overflow.
		const assertDialogFooterPainted = async (where) => {
			const g = await report.evaluate((d) => {
				const cs = getComputedStyle(d);
				const r = d.getBoundingClientRect();
				const bt = parseFloat(cs.borderTopWidth) || 0;
				const bl = parseFloat(cs.borderLeftWidth) || 0;
				const client = { top: r.top + bt, left: r.left + bl, bottom: r.top + bt + d.clientHeight, right: r.left + bl + d.clientWidth };
				const f = d.querySelector('.kzc-dialog__footer').getBoundingClientRect();
				const a = d.querySelector('#kzc-transfer-confirm').getBoundingClientRect();
				return { client, dScrollH: d.scrollHeight, dClientH: d.clientHeight,
					footer: { top: f.top, bottom: f.bottom, left: f.left, right: f.right },
					apply: { top: a.top, bottom: a.bottom, left: a.left, right: a.right } };
			});
			for (const [name, box] of Object.entries({ footer: g.footer, apply: g.apply })) {
				expect(box.bottom, `${where}: ${name} bottom ${Math.round(box.bottom)} hangs below dialog client box ${Math.round(g.client.bottom)}`).toBeLessThanOrEqual(g.client.bottom + 0.5);
				expect(box.top, `${where}: ${name} top above dialog client box`).toBeGreaterThanOrEqual(g.client.top - 0.5);
				expect(box.right, `${where}: ${name} right outside dialog client box`).toBeLessThanOrEqual(g.client.right + 0.5);
				expect(box.left, `${where}: ${name} left outside dialog client box`).toBeGreaterThanOrEqual(g.client.left - 0.5);
			}
			expect(g.dScrollH - g.dClientH,
				`${where}: dialog shell scrolls (scrollHeight ${g.dScrollH} > clientHeight ${g.dClientH}) — .kzc-dialog__body must absorb the overflow`)
				.toBeLessThanOrEqual(1);
		};
		await assertDialogFooterPainted('1280');
		await assertDialogGeometry(report, 'centered');

		// Dialog-open sweep — the contract scans dialog[open] in the same pass.
		// listAllow: .kzc-transfer-group uls are mono-font machine-name manifest
		// readouts inside <details> disclosures — marker-less by design (the
		// padding-inline-start is indentation, not lost-marker residue); the
		// identical rendered state shipped in the r24/r25 report-dialog crafts.
		await assertAtlasRenderedSurface(page, {
			content: '#kzc-main-content',
			navExclude: '.kzc-tabbar, nav',
			listAllow: '.kzc-transfer-group',
		});

		await shot(page, 'kzc-staff-transfer-replace-confirm-light-1280');
		await page.setViewportSize({ width: 375, height: 812 });
		await page.waitForTimeout(300);
		await assertDialogFooterPainted('375');
		await assertDialogGeometry(report, 'fullbleed');
		await shot(page, 'kzc-staff-transfer-replace-confirm-light-375');
		// Never apply — replace is destructive; close the dialog instead.
		await page.keyboard.press('Escape');
		await expect(report).toBeHidden();
	});
});

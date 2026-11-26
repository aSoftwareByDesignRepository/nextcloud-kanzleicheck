// @ts-check
/**
 * Staff board drag & drop + status select — the two move paths share one
 * CAS write. Covers: drop lands the card in the target column and persists,
 * the native select is the keyboard/AT equivalent, a stale revision (a
 * colleague moved the card meanwhile) conflicts and reloads instead of
 * silently diverging, and the drop indicator/same-column no-op behave.
 * Plus the card-head visibility eye: toggles portal_visibility via the same
 * CAS PUT and persists across re-renders.
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
const REF = 'DND-E2E-1-' + TAG;
const ORG_NAME = 'DnD Board Mandat ' + TAG;
let orgId = 0;
let taskId = 0;

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
	// Never swallow exec failures — a silently failed UPDATE reads as a no-op
	// and makes a later assertion debug the wrong thing entirely.
	if (r.status !== 0) throw new Error(`db() failed status=${r.status} err=${r.error ? r.error.message : ''} stderr=${(r.stderr || '').trim()} sql=${sql.slice(0, 120)}`);
	return (r.stdout || '').trim();
}

/** Worker restarts re-run beforeAll and re-seed the fixture — a dependent test
 * must establish its own starting column instead of inheriting one. */
function ensureStatus(status) {
	db(`UPDATE oc_kzc_task SET status='${status}', done_at=${status === 'done' ? 'NOW()' : 'NULL'} WHERE id=${taskId} AND status<>'${status}'`);
}

const cardSel = () => `[data-task-id="${taskId}"]`;
const colSel = (s) => `.kzc-col[data-status="${s}"]`;

/** Real HTML5 drag gesture via DragEvents — Playwright mouse moves don't drive HTML5 DnD. */
async function dragCardTo(page, status) {
	await page.evaluate(([sel, st]) => {
		const dt = new DataTransfer();
		const card = document.querySelector(sel);
		const col = document.querySelector('.kzc-col[data-status="' + st + '"]');
		if (!card || !col) throw new Error('drag endpoints missing');
		const handle = card.querySelector('.kzc-card2__drag');
		handle.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
		col.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
		col.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
		handle.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: dt }));
	}, [cardSel(), status]);
}

async function openBoard(page) {
	await page.goto(`${APP}/?view=board`, { waitUntil: 'domcontentloaded' });
	await page.locator(cardSel()).waitFor({ state: 'visible', timeout: 15000 });
}

test.describe('staff board drag & drop', () => {
	test.beforeAll(() => {
		db(`DELETE FROM oc_kzc_task WHERE org_id IN (SELECT id FROM oc_kzc_org WHERE ref='${REF}');
DELETE FROM oc_kzc_org WHERE ref='${REF}';
INSERT INTO oc_kzc_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('${ORG_NAME}','${REF}','company','active',0,'system:test',NOW(),NOW());
INSERT INTO oc_kzc_task (org_id,title,status,priority,portal_visibility,revision,created_by,created_at,updated_at) SELECT id,'DndCard','new','normal','status',1,'system:test',NOW(),NOW() FROM oc_kzc_org WHERE ref='${REF}';`);
		orgId = parseInt(db(`SELECT id FROM oc_kzc_org WHERE ref='${REF}'`), 10);
		taskId = parseInt(db(`SELECT id FROM oc_kzc_task WHERE org_id=${orgId}`), 10);
		test.skip(!(orgId > 0 && taskId > 0), 'seed failed');
	});

	test.afterAll(() => {
		if (taskId > 0) {
			db(`DELETE FROM oc_kzc_audit WHERE target_type='task' AND target_id=${taskId};
DELETE FROM oc_kzc_task WHERE id=${taskId};`);
		}
		if (orgId > 0) db(`DELETE FROM oc_kzc_org WHERE id=${orgId}`);
	});

	test('drag handle + status select render; drop moves the card and persists', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await openBoard(page);

		const card = page.locator(cardSel());
		await expect(page.locator(colSel('new')).locator(cardSel())).toBeVisible();
		const handle = card.locator('.kzc-card2__drag');
		await expect(handle).toBeVisible();
		await expect(handle).toHaveAttribute('draggable', 'true');
		await expect(handle).toHaveAttribute('aria-label', /DndCard/);
		const sel = card.locator('[data-kzc-status-select]');
		await expect(sel).toHaveValue('new');
		await expect(sel.locator('option')).toHaveCount(4);

		await dragCardTo(page, 'done');
		await expect(page.locator(colSel('done')).locator(cardSel())).toBeVisible({ timeout: 15000 });
		await expect(page.locator(colSel('new')).locator(cardSel())).toHaveCount(0);
		expect(db(`SELECT status FROM oc_kzc_task WHERE id=${taskId}`)).toBe('done');
		await expect(page.locator(colSel('done')).locator(cardSel()).locator('[data-kzc-status-select]')).toHaveValue('done');
	});

	test('status select moves the card (keyboard path)', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		ensureStatus('done');
		await openBoard(page);

		await page.locator(colSel('done')).locator(cardSel()).locator('[data-kzc-status-select]').selectOption('waiting');
		await expect(page.locator(colSel('waiting')).locator(cardSel())).toBeVisible({ timeout: 15000 });
		expect(db(`SELECT status FROM oc_kzc_task WHERE id=${taskId}`)).toBe('waiting');
		expect(db(`SELECT done_at IS NULL FROM oc_kzc_task WHERE id=${taskId}`)).toBe('1');
	});

	test('stale revision conflicts: warn + reload, no silent divergence', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		ensureStatus('waiting');
		await openBoard(page);
		// A colleague moved the card after our render — our revision is stale.
		db(`UPDATE oc_kzc_task SET revision=revision+1 WHERE id=${taskId}`);

		await page.locator(colSel('waiting')).locator(cardSel()).locator('[data-kzc-status-select]').selectOption('in_progress');
		await expect(page.locator('.kzc-toast')).toBeVisible({ timeout: 15000 });
		// Server truth wins: card stays where it really is, select reverts.
		await expect(page.locator(colSel('waiting')).locator(cardSel())).toBeVisible({ timeout: 15000 });
		await expect(page.locator(colSel('waiting')).locator(cardSel()).locator('[data-kzc-status-select]')).toHaveValue('waiting');
		expect(db(`SELECT status FROM oc_kzc_task WHERE id=${taskId}`)).toBe('waiting');
	});

	test('drop indicator highlights the target; same-column drop is a no-op', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		ensureStatus('waiting');
		await openBoard(page);

		// dragstart + hover another column → indicator; leave → cleared
		await page.evaluate(([sel]) => {
			const card = document.querySelector(sel);
			const col = document.querySelector('.kzc-col[data-status="in_progress"]');
			const handle = card.querySelector('.kzc-card2__drag');
			handle.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
			col.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
		}, [cardSel()]);
		await expect(page.locator(colSel('in_progress'))).toHaveClass(/kzc-col--dragover/);

		// drop back onto the card's own column → no write, no state change
		const revBefore = db(`SELECT revision FROM oc_kzc_task WHERE id=${taskId}`);
		await page.evaluate(([sel]) => {
			const col = document.querySelector('.kzc-col[data-status="waiting"]');
			col.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
			col.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
			document.querySelector(sel).querySelector('.kzc-card2__drag')
				.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
		}, [cardSel()]);
		await expect(page.locator(colSel('in_progress'))).not.toHaveClass(/kzc-col--dragover/);
		await expect(page.locator('.kzc-board')).not.toHaveClass(/kzc-board--dragging/);
		expect(db(`SELECT revision FROM oc_kzc_task WHERE id=${taskId}`)).toBe(revBefore);
		await expect(page.locator(colSel('waiting')).locator(cardSel())).toBeVisible();
	});

	test('visibility eye: toggle flips portal_visibility, persists, keyboard works', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await openBoard(page);

		// seeded portal_visibility='status' → open eye, pressed, transparent fill
		const vis = () => page.locator(cardSel()).locator('[data-kzc-visibility-toggle]');
		await expect(vis()).toBeVisible();
		await expect(vis()).toHaveAttribute('aria-pressed', 'true');
		await expect(vis()).toHaveAttribute('title', /visible|sichtbar/i);
		await expect(vis()).not.toHaveClass(/kzc-card2__vis--off/);
		// on-state must be genuinely transparent — NC core repaints bare
		// <button>s with a pale fill at 0-1-1; the armor has to beat it
		const bgOn = await vis().evaluate((el) => getComputedStyle(el).backgroundColor);
		expect(['rgba(0, 0, 0, 0)', 'transparent']).toContain(bgOn);

		// click → eye-off, pressed=false, filled background, DB flipped
		await vis().click();
		await expect(vis()).toHaveAttribute('aria-pressed', 'false', { timeout: 15000 });
		await expect(vis()).toHaveClass(/kzc-card2__vis--off/);
		await expect(vis()).toHaveAttribute('title', /hidden|unsichtbar/i);
		const bg = await vis().evaluate((el) => getComputedStyle(el).backgroundColor);
		expect(bg).not.toBe('rgba(0, 0, 0, 0)');
		expect(bg).not.toBe('transparent');
		expect(db(`SELECT portal_visibility FROM oc_kzc_task WHERE id=${taskId}`)).toBe('none');

		// keyboard path: native button takes Enter — back to visible
		await vis().press('Enter');
		await expect(vis()).toHaveAttribute('aria-pressed', 'true', { timeout: 15000 });
		await expect(vis()).not.toHaveClass(/kzc-card2__vis--off/);
		expect(db(`SELECT portal_visibility FROM oc_kzc_task WHERE id=${taskId}`)).toBe('status');
	});

	test('detail dialog: opens on title, edits persist via CAS, conflict repopulates', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await openBoard(page);
		const dlg = () => page.locator('dialog.kzc-dialog[open]');

		// title is a real button — transparent ghost, not core's pale fill
		const titleBtn = page.locator(cardSel()).locator('.kzc-card2__title');
		await expect(titleBtn).toHaveText('DndCard');
		const bg = await titleBtn.evaluate((el) => getComputedStyle(el).backgroundColor);
		expect(['rgba(0, 0, 0, 0)', 'transparent']).toContain(bg);

		await titleBtn.click();
		await expect(dlg()).toBeVisible({ timeout: 15000 });
		await expect(dlg().locator('#td-title')).toHaveValue('DndCard');
		await expect(dlg().locator('[data-kzc-td-meta]')).toContainText(/DnD Board Mandat/);
		await expect(dlg().locator('[data-kzc-td-audit]')).toContainText(/Created|Erstellt/);

		// empty title → client-side field error, dialog stays open, no write
		await dlg().locator('#td-title').fill('');
		await dlg().locator('.kzc-btn--primary').click();
		await expect(dlg().locator('.kzc-field-error')).toBeVisible({ timeout: 15000 });
		expect(db(`SELECT title FROM oc_kzc_task WHERE id=${taskId}`)).toBe('DndCard');

		// fill + Enter inside a field → implicit submit routes to the CAS save
		await dlg().locator('#td-title').fill('DndCard');
		await dlg().locator('#td-note').fill('hello detail');
		await dlg().locator('#td-priority').selectOption('high');
		await dlg().locator('#td-assignee').fill('alice');
		await dlg().locator('#td-title').press('Enter');
		await expect(dlg()).toBeHidden({ timeout: 15000 });
		expect(db(`SELECT note,priority,assignee FROM oc_kzc_task WHERE id=${taskId}`)).toBe('hello detail\thigh\talice');
		await expect(page.locator(cardSel()).locator('.kzc-cardmeta')).toContainText(/high/i);

		// reopen — values round-tripped; Escape discards without writing
		await page.locator(cardSel()).locator('.kzc-card2__title').click();
		await expect(dlg()).toBeVisible({ timeout: 15000 });
		await expect(dlg().locator('#td-note')).toHaveValue('hello detail');
		await expect(dlg().locator('#td-priority')).toHaveValue('high');
		await dlg().locator('#td-note').fill('discarded');
		await page.keyboard.press('Escape');
		await expect(dlg()).toBeHidden();
		expect(db(`SELECT note FROM oc_kzc_task WHERE id=${taskId}`)).toBe('hello detail');

		// colleague wrote meanwhile → save conflicts, dialog stays open on
		// fresh data (their title shown, not clobbered), retry then succeeds
		await page.locator(cardSel()).locator('.kzc-card2__title').click();
		await expect(dlg()).toBeVisible({ timeout: 15000 });
		db(`UPDATE oc_kzc_task SET revision=revision+1, title='Colleague' WHERE id=${taskId}`);
		await dlg().locator('#td-note').fill('mine');
		await dlg().locator('.kzc-btn--primary').click();
		await expect(page.locator('.kzc-toast')).toBeVisible({ timeout: 15000 });
		await expect(dlg().locator('#td-title')).toHaveValue('Colleague', { timeout: 15000 });
		expect(db(`SELECT title FROM oc_kzc_task WHERE id=${taskId}`)).toBe('Colleague');
		await dlg().locator('#td-note').fill('mine2');
		await dlg().locator('.kzc-btn--primary').click();
		await expect(dlg()).toBeHidden({ timeout: 15000 });
		expect(db(`SELECT note,title FROM oc_kzc_task WHERE id=${taskId}`)).toBe('mine2\tColleague');
	});
});

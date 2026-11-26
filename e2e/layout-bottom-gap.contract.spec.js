// @ts-check
/**
 * ATLAS_SHELL_BOTTOM_GAP_CONTRACT — every staff surface keeps the
 * design-system shell bottom margin (space-7, 48px) between the last
 * content and the #app-content scrollport edge at maximum scroll.
 *
 * Guards shell_wrapper_shrink_bottom_gap: the check-shell makes
 * #app-content a column flex container, so #app-content-wrapper is a flex
 * item whose default flex-shrink let NC core's min-height:100% clamp it to
 * one viewport — the 48px padding-bottom then sat inside the shrunken box
 * while main overflowed past it, painting every tall page flush to the
 * viewport edge. The contract asserts the outcome (visible gap), not the
 * mechanism, so it fails on any regression that removes the bottom margin.
 *
 * Each surface is forced to overflow via a short viewport (content can only
 * collapse the gap when it is taller than the scrollport), scrolled to the
 * absolute bottom, then measured: distance from #kzc-main-content's bottom
 * edge to the scrollport's bottom edge must be at least 44px (space-7 = 48
 * minus subpixel/border tolerance) and at most 96px (padding exists but is
 * not inflated by stray margins).
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');
const { assertAtlasShellBottomGap } = require('../../_shared/e2e/atlas-bottom-gap-contract');

const APP = '/index.php/apps/kanzleicheck';
const authed = hasCreds();

const STAFF_VIEWS = [
	'overview', 'inbox', 'board', 'requests', 'orgs',
	'uploads', 'holds', 'forms', 'people',
];
const SETTINGS_SECTIONS = [
	'access', 'general', 'documents', 'mail',
	'license', 'diagnostics', 'transfer', 'about',
];
const GUEST_PAGES = [
	{ id: 'portal-login', path: '/portal/login' },
	{ id: 'portal-notfound', path: '/portal/u/does-not-exist' },
];

const MIN_GAP = 44; // space-7 (48) minus subpixel tolerance
const GAP_OPTS = { main: '#kzc-main-content', minGapPx: MIN_GAP, maxGapPx: 96 };

/** The SPA mounts #kzc-staff-view inside #kzc-main-content before data —
 * a naive main.children check resolves early while #kzc-body is still
 * empty. When the mount exists, only a real content marker (card / list /
 * table / board / empty-state / callout) means settled render. */
async function waitForViewContent(page) {
	await page.waitForFunction(() => {
		const mount = document.querySelector('#kzc-staff-view');
		if (mount) {
			const body = mount.querySelector('#kzc-body');
			return !!(body && body.querySelector(
				'.kzc-card,.kzc-list,.kzc-table,.kzc-board,.kzc-empty-state,.kzc-callout'));
		}
		const main = document.querySelector('#kzc-main-content');
		return main && main.children.length > 0;
	}, { timeout: 20000 });
}

test.describe.configure({ mode: 'serial' });

test.describe('ATLAS_SHELL_BOTTOM_GAP_CONTRACT', () => {
	test('staff views keep the space-7 bottom margin at max scroll', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 420 });
		await login(page, creds());
		let overflowed = 0;
		for (const v of STAFF_VIEWS) {
			await page.goto(`${APP}/?view=${v}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#kzc-main-content', { timeout: 20000 });
			await waitForViewContent(page);
			const m = await assertAtlasShellBottomGap(page, GAP_OPTS);
			if (m.overflows) overflowed++;
		}
		expect(overflowed, 'at least 3 staff views must overflow the 420px scrollport to exercise the shrink path')
			.toBeGreaterThanOrEqual(3);
	});

	test('settings sections keep the space-7 bottom margin at max scroll', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 1280, height: 420 });
		await login(page, creds());
		let overflowed = 0;
		for (const s of SETTINGS_SECTIONS) {
			await page.goto(`${APP}/settings/${s}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#kzc-main-content', { timeout: 20000 });
			await waitForViewContent(page);
			const m = await assertAtlasShellBottomGap(page, GAP_OPTS);
			if (m.overflows) overflowed++;
		}
		expect(overflowed, 'at least one settings section must overflow the 420px scrollport')
			.toBeGreaterThanOrEqual(1);
	});

	test('guest portal pages keep a bottom margin at max scroll', async ({ page }) => {
		// Portal is a guest surface — no login needed.
		await page.setViewportSize({ width: 800, height: 480 });
		for (const p of GUEST_PAGES) {
			await page.goto(`${APP}${p.path}`, { waitUntil: 'domcontentloaded' });
			await page.locator('.kzc-portal').waitFor({ state: 'visible', timeout: 20000 });
			let m = {};
			await expect.poll(async () => {
				m = await page.evaluate(() => {
					window.scrollTo(0, document.body.scrollHeight);
					const portal = document.querySelector('.kzc-portal');
					// lowest in-flow, non-fixed child of the portal root
					let last = null;
					for (const el of portal.children) {
						const cs = getComputedStyle(el);
						if (cs.position === 'fixed' || cs.display === 'none') continue;
						if (!last || el.getBoundingClientRect().bottom > last.getBoundingClientRect().bottom) last = el;
					}
					if (!last) last = portal.lastElementChild;
					return {
						overflows: document.scrollingElement.scrollHeight > innerHeight + 4,
						gap: portal.getBoundingClientRect().bottom - last.getBoundingClientRect().bottom,
						padB: parseFloat(getComputedStyle(portal).paddingBottom),
						lastTag: last ? last.tagName + '.' + String(last.className).split(' ')[0] : 'none',
					};
				});
				return m.gap || -1;
			}, { timeout: 10000 }).toBeGreaterThanOrEqual(MIN_GAP);
			expect(m.lastTag, `${p.id} produced no in-flow portal child`).not.toBe('none');
			expect(m.overflows, `${p.id} must overflow a 480px viewport for the gap contract`).toBe(true);
			// .kzc-portal is min-height:100vh — free space inflates the gap on
			// short pages legitimately, so there is no upper bound here; the
			// contract is the bottom-padding floor plus a real visible gap.
			expect(m.padB, `${p.id} portal padding-bottom ${m.padB}px — expected >= ${MIN_GAP}px`)
				.toBeGreaterThanOrEqual(MIN_GAP);
		}
	});

	test('mobile viewport: staff shell keeps the bottom margin (safe-area floor)', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await page.setViewportSize({ width: 390, height: 380 });
		await login(page, creds());
		let overflowed = 0;
		for (const v of ['overview', 'orgs', 'people']) {
			await page.goto(`${APP}/?view=${v}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#kzc-main-content', { timeout: 20000 });
			await waitForViewContent(page);
			const m = await assertAtlasShellBottomGap(page, GAP_OPTS);
			if (m.overflows) overflowed++;
		}
		expect(overflowed, 'at least one mobile view must overflow the 380px scrollport')
			.toBeGreaterThanOrEqual(1);
	});
});

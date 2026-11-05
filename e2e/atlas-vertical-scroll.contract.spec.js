// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — the staff shell must live inside NC's
 * #app-content scrollport so tall views reach their last row.
 * Guards ptl-c5 (nc_content_scroll_container_bypass): a template mounted
 * directly under #content{overflow:clip} truncates every below-fold row.
 * Seeds a unique org cohort so the list overflows deterministically, then
 * asserts the last row is reachable at desktop and phone heights.
 */
const { test, expect } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const authed = hasCreds();
const SEED_N = 20;

async function seedOrgs(request, auth, tag) {
	const ids = [];
	for (let i = 0; i < SEED_N; i++) {
		const r = await request.fetch(`${BASE}${APP}/api/portal/orgs`, {
			method: 'POST',
			headers: { Authorization: auth },
			data: { name: `ScrollFixture ${tag} ${String(i).padStart(2, '0')}`, ref: `SF-${tag}-${i}`, kind: 'company' },
		});
		const j = await r.json();
		ids.push(j.data.org.id);
	}
	return ids;
}

async function offboardAll(request, auth, ids) {
	for (const id of ids) {
		await request.fetch(`${BASE}${APP}/api/portal/orgs/${id}/offboard`, {
			method: 'POST', headers: { Authorization: auth },
		});
	}
}

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('staff clients list scrolls to the last row (1280x640)', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
		const ids = await seedOrgs(request, auth, tag);
		try {
			await page.setViewportSize({ width: 1280, height: 640 });
			await login(page, creds());
			await page.goto(`${APP}/?view=orgs`, { waitUntil: 'domcontentloaded' });
			await page.locator('#ptl-orglist .ptl-row').first().waitFor({ state: 'visible', timeout: 20000 });
			await assertAtlasVerticalScrollReachable(page, {
				scrollport: '#app-content',
				target: '#ptl-orglist .ptl-row:last-child',
				bottomSlopPx: 12,
			});
		} finally {
			await offboardAll(request, auth, ids);
		}
	});

	test('staff clients list stays reachable at phone height (390x667)', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
		const ids = await seedOrgs(request, auth, tag);
		try {
			await page.setViewportSize({ width: 390, height: 667 });
			await login(page, creds());
			await page.goto(`${APP}/?view=orgs`, { waitUntil: 'domcontentloaded' });
			await page.locator('#ptl-orglist .ptl-row').first().waitFor({ state: 'visible', timeout: 20000 });
			await assertAtlasVerticalScrollReachable(page, {
				scrollport: '#app-content',
				target: '#ptl-orglist .ptl-row:last-child',
				bottomSlopPx: 16,
			});
		} finally {
			await offboardAll(request, auth, ids);
		}
	});
});

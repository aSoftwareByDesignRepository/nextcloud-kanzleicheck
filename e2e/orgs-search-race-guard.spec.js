// @ts-check
/**
 * ATLAS ptl-c6 — stale_response_render_clobber guard: a superseded list fetch
 * must never paint over a newer filtered result. The mount-time unfiltered
 * /api/portal/orgs response is delayed via route interception; the #ptl-q
 * filter fetches ?q= which resolves first and paints the filtered list. Once
 * the stale mount response finally lands, the generation guard must drop it —
 * the list keeps showing exactly the filtered rows.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const authed = hasCreds();
const STALE_DELAY_MS = 1200;

test.describe('staff orgs search stale-response guard (c6)', () => {
	test('delayed unfiltered mount response cannot clobber the filtered list', async ({ page, request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const orgName = `RaceGuard ${tag}`;
		const org = (await request.fetch(`${BASE}${APP}/api/portal/orgs`, {
			method: 'POST',
			headers: { Authorization: auth },
			data: { name: orgName, ref: `RG-${tag}`, kind: 'company' },
		}).then((r) => r.json())).data.org;
		expect(org.id).toBeGreaterThan(0);

		try {
			// Delay ONLY the bare collection GET (the mount fetch). The ?q=
			// filtered fetch passes straight through and resolves first.
			await page.route('**/api/portal/orgs', async (route) => {
				const u = new URL(route.request().url());
				if (u.pathname.endsWith('/api/portal/orgs') && !u.searchParams.has('q')) {
					await new Promise((r) => setTimeout(r, STALE_DELAY_MS));
				}
				return route.continue();
			});

			await page.setViewportSize({ width: 1280, height: 800 });
			await login(page, creds());
			await page.goto(`${APP}/?view=orgs`, { waitUntil: 'domcontentloaded' });
			await page.locator('#ptl-q').waitFor({ state: 'visible', timeout: 20000 });

			const filtered = page.waitForResponse(
				(r) => r.url().includes('/api/portal/orgs?q=') && r.request().method() === 'GET',
				{ timeout: 20000 },
			);
			await page.locator('#ptl-q').fill(orgName);
			await filtered;

			const rows = page.locator('#ptl-orglist .ptl-row');
			await expect(rows).toHaveCount(1);
			await expect(rows.first()).toContainText(orgName);

			// Wait until the stale mount response has certainly landed, then
			// assert the filtered paint survived — an unguarded last-writer-wins
			// would have repainted the full unfiltered list by now.
			await page.waitForTimeout(STALE_DELAY_MS + 1200);
			await expect(rows).toHaveCount(1);
			await expect(rows.first()).toContainText(orgName);
		} finally {
			const ob = await request.fetch(`${BASE}${APP}/api/portal/orgs/${org.id}/offboard`, {
				method: 'POST', headers: { Authorization: auth },
			});
			expect([200, 204]).toContain(ob.status());
		}
	});
});

// @ts-check
/**
 * Deeplink cap-fallback contract — the orgs list is capped (200), so a
 * notification deeplink into an older org must still land: staff-app.js
 * falls back to the inbox conversation list (uncapped) for the org name.
 * Simulates the cap by route-intercepting /api/portal/orgs with a list
 * that omits the target org — the pre-fallback code dropped to the inbox
 * list and never opened the conversation.
 */
const { test, expect } = require('@playwright/test');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const authed = hasCreds();

test.describe('inbox deeplink cap fallback', () => {
	test('org outside the capped orgs list still opens its conversation', async ({ page }) => {
		test.skip(!authed, 'no creds');
		await login(page, creds());
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view #kzc-body').waitFor({ state: 'visible', timeout: 20000 });
		const org = await page.evaluate(async () => {
			// Must be an org WITH an OPEN conversation — same pick rule as the
			// craft-web deeplink leg (archived convs lose the composer).
			const r = await fetch('api/portal/inbox', { headers: { Accept: 'application/json' } }).then((x) => x.json());
			const convs = (r && r.data && r.data.conversations) || [];
			const open = convs.find((c) => c.state !== 'archived');
			return open ? { id: open.orgId, name: open.orgName } : null;
		});
		test.skip(!org, 'no open conversation fixture');

		// Cap simulation: the orgs COLLECTION resolves WITHOUT the target
		// org, forcing the boot code down its new inbox-conversations
		// fallback. Exact-endpoint pattern — /orgs/<id>/messages must not
		// be intercepted or pullConv starves #kzc-msgs.
		await page.route('**/api/portal/orgs', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: { orgs: [] } }),
			}));
		await page.goto(`${APP}/?view=inbox&org=${org.id}`, { waitUntil: 'domcontentloaded' });
		await page.locator('#kzc-staff-view #kzc-body').waitFor({ state: 'visible', timeout: 20000 });
		// openConv() appends the composer synchronously; #kzc-msgs is
		// zero-size until messages paint, so attached, not visible.
		await expect(page.locator('#kzc-msgs'), 'conversation must open via inbox-fallback name').toBeAttached({ timeout: 15000 });
		await expect(page.locator('#kzc-cf'), 'composer must render for an open conversation').toBeVisible({ timeout: 15000 });
	});
});

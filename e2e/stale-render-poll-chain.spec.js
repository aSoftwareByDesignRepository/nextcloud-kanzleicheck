// @ts-check
/**
 * ATLAS kzc-c7 / kzc-c8 — stale_response_render_clobber + poller_chain_fork.
 *
 * Leg 1 (staff c8): sends while a conv fetch is in flight must coalesce onto
 *   the single chain — never fork a second parallel poller. A held message
 *   GET makes the window deterministic: after two sends exactly ONE fetch is
 *   still pending, and after release each message paints exactly once.
 * Leg 2 (staff c7): an org-A messages response resolving after openConv(B)
 *   must never paint into B's thread nor corrupt B's cursor.
 * Leg 3 (portal c7): the start view paints after its fetches resolve — a
 *   delayed /contact+/todo pair must not clobber the chat view the user
 *   navigated to. Leg 4 repeats the send-fork proof on the portal chain.
 */
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const { createHash } = require('crypto');
const { login, hasCreds, creds, BASE } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/kanzleicheck';
const COMPOSE_ROOT = require('path').resolve(__dirname, '../../..');
const authed = hasCreds();

function db(sql) {
	const r = spawnSync('docker', ['compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', sql],
	{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 });
	return (r.stdout || '').trim();
}

async function readOtp(email, tries = 30) {
	const sha = createHash('sha256').update(email).digest('hex');
	for (let i = 0; i < tries; i++) {
		const code = db("SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'$.code')) " +
			`FROM oc_kzc_mail_outbox WHERE kind='otp' AND to_hash='${sha}' ` +
			'AND discard=0 ORDER BY id DESC LIMIT 1;').split('\n').pop().trim();
		if (/^\d{6}$/.test(code)) return code;
		await new Promise((r) => setTimeout(r, 500));
	}
	throw new Error(`no OTP in mail outbox for ${email}`);
}

async function portalLogin(page, email) {
	await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
	await page.locator('#kzc-send-form').waitFor({ state: 'visible', timeout: 15000 });
	await page.locator('#kzc-email').fill(email);
	await page.locator('#kzc-send-btn').click();
	await page.locator('#kzc-verify-form').waitFor({ state: 'visible', timeout: 15000 });
	await page.locator('#kzc-code').fill(await readOtp(email));
	// 6-digit input auto-submits — no click: a second POST burns the shared
	// /portal/api/auth/verify AnonRateLimit budget (20/300s per IP, non-JSON
	// 429 → generic status line) twice per login and saturates the suite.
	await page.waitForURL('**/apps/kanzleicheck/portal**', { timeout: 20000 });
}

/** Holds GETs matching match(url) in `pending` until release() flips the gate. */
function msgGate(page, match) {
	const state = { hold: true, pending: [], count: 0 };
	page.route('**/*', async (route) => {
		const req = route.request();
		if (req.method() === 'GET' && match(req.url())) {
			state.count++;
			if (state.hold) { state.pending.push(route); return; }
		}
		return route.continue();
	});
	return {
		state,
		async release() {
			state.hold = false;
			for (const r of state.pending.splice(0)) await r.continue();
		},
	};
}

test.describe('staff conv poll-chain + stale-response guards (c7/c8)', () => {
	let auth;
	let api;
	const mkApi = (request) => async (method, u, data) => request.fetch(`${BASE}${APP}${u}`, {
		method, data, headers: { Authorization: auth },
	}).then(async (r) => {
		const body = await r.text();
		return body ? JSON.parse(body) : { success: r.ok };
	});

	test.beforeEach(async ({ request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		api = mkApi(request);
	});

	test('c8: sends during an in-flight pull coalesce — one chain, each row painted once', async ({ page }) => {
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const org = (await api('POST', '/api/portal/orgs', { name: `ForkGuard ${tag}`, ref: `FG-${tag}`, kind: 'company' })).data.org;
		const seed = (await api('POST', `/api/portal/orgs/${org.id}/messages`, {
			body: `seed-${tag}`, clientUuid: crypto.randomUUID(),
		})).data;
		expect(seed.message.id).toBeGreaterThan(0);

		try {
			const gate = msgGate(page, (u) => u.includes(`/api/portal/orgs/${org.id}/messages`));
			await login(page, creds());
			await page.goto(`${APP}/?view=inbox&org=${org.id}`, { waitUntil: 'domcontentloaded' });
			await page.locator('#kzc-cm').waitFor({ state: 'visible', timeout: 20000 });
			await expect.poll(() => gate.state.pending.length, { timeout: 15000 }).toBe(1);

			// The mount pull is held in flight — send two messages against it.
			for (const body of [`fork-a-${tag}`, `fork-b-${tag}`]) {
				const posted = page.waitForResponse(
					(r) => r.url().includes(`/orgs/${org.id}/messages`) && r.request().method() === 'POST',
					{ timeout: 20000 });
				await page.locator('#kzc-cm').fill(body);
				await page.locator('#kzc-cm').press('Enter');
				await posted;
			}
			await page.waitForTimeout(500);
			// Forked chains would have fired 1 fetch per send (3 pending).
			expect(gate.state.pending.length).toBe(1);
			expect(gate.state.count).toBe(1);

			await gate.release();
			const rows = page.locator('#kzc-msgs .kzc-msg');
			await expect(rows).toHaveCount(3, { timeout: 20000 });
			for (const body of [`seed-${tag}`, `fork-a-${tag}`, `fork-b-${tag}`]) {
				await expect(page.locator('.kzc-msg__body', { hasText: body })).toHaveCount(1);
			}
			// Exactly one coalesced follow-up fetch — no extra chains.
			await page.waitForTimeout(600);
			expect(gate.state.count).toBe(2);
		} finally {
			await api('POST', `/api/portal/orgs/${org.id}/offboard`, {});
		}
	});

	test('c7: org-A response resolving after openConv(B) never paints into B', async ({ page }) => {
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const orgA = (await api('POST', '/api/portal/orgs', { name: `StaleA ${tag}`, ref: `SA-${tag}`, kind: 'company' })).data.org;
		const orgB = (await api('POST', '/api/portal/orgs', { name: `StaleB ${tag}`, ref: `SB-${tag}`, kind: 'company' })).data.org;
		for (const body of [`orgA-1-${tag}`, `orgA-2-${tag}`]) {
			await api('POST', `/api/portal/orgs/${orgA.id}/messages`, { body, clientUuid: crypto.randomUUID() });
		}
		for (const body of [`orgB-1-${tag}`, `orgB-2-${tag}`]) {
			await api('POST', `/api/portal/orgs/${orgB.id}/messages`, { body, clientUuid: crypto.randomUUID() });
		}

		try {
			const gate = msgGate(page, (u) => u.includes(`/api/portal/orgs/${orgA.id}/messages`));
			await login(page, creds());
			// Enter via the inbox row click — the ?view=inbox&org= deep link
			// silently falls back to the list when the org-name lookup races
			// under parallel-suite load (robustness: only the row click is
			// deterministic here).
			await page.goto(`${APP}/?view=inbox`, { waitUntil: 'domcontentloaded' });
			const rowA = page.locator('.kzc-row', { hasText: `StaleA ${tag}` });
			await rowA.waitFor({ state: 'visible', timeout: 30000 });
			await rowA.click();
			await page.locator('#kzc-cm').waitFor({ state: 'visible', timeout: 20000 });
			await expect.poll(() => gate.state.pending.length, { timeout: 15000 }).toBe(1);

			// Switch to conversation B while A's response is still in flight.
			await page.locator('#app-navigation .kzc-nav__link[href*="view=inbox"]').click();
			const rowB = page.locator('.kzc-row', { hasText: `StaleB ${tag}` });
			await rowB.waitFor({ state: 'visible', timeout: 20000 });
			await rowB.click();
			const rows = page.locator('#kzc-msgs .kzc-msg');
			await expect(rows).toHaveCount(2, { timeout: 20000 });

			// Release A's stale response — it must be dropped, not painted.
			await gate.release();
			await page.waitForTimeout(800);
			await expect(rows).toHaveCount(2);
			await expect(page.locator('.kzc-msg__body', { hasText: 'orgA-' })).toHaveCount(0);
			await expect(page.locator('.kzc-msg__body', { hasText: 'orgB-' })).toHaveCount(2);

			// Cursor must be uncorrupted: a new B send paints via the live chain.
			const posted = page.waitForResponse(
				(r) => r.url().includes(`/orgs/${orgB.id}/messages`) && r.request().method() === 'POST',
				{ timeout: 20000 });
			await page.locator('#kzc-cm').fill(`orgB-3-${tag}`);
			await page.locator('#kzc-cm').press('Enter');
			await posted;
			await expect(rows).toHaveCount(3, { timeout: 15000 });
		} finally {
			await api('POST', `/api/portal/orgs/${orgA.id}/offboard`, {});
			await api('POST', `/api/portal/orgs/${orgB.id}/offboard`, {});
		}
	});
});

test.describe('portal view stale-render + poll-chain guards (c7/c8)', () => {
	let auth;
	let api;
	const mkApi = (request) => async (method, u, data) => request.fetch(`${BASE}${APP}${u}`, {
		method, data, headers: { Authorization: auth },
	}).then(async (r) => {
		const body = await r.text();
		return body ? JSON.parse(body) : { success: r.ok };
	});

	test.beforeEach(async ({ request }) => {
		test.skip(!authed, 'no creds');
		const { username, password } = creds();
		auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64');
		api = mkApi(request);
	});

	test('c7: delayed start fetches cannot clobber chat; c8: sends coalesce onto one chain', async ({ page }) => {
		const tag = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
		const email = `kzc-chain-${tag}@test.invalid`;
		const org = (await api('POST', '/api/portal/orgs', { name: `PortalChain ${tag}`, ref: `PC-${tag}`, kind: 'company' })).data.org;
		const m = await api('POST', `/api/portal/orgs/${org.id}/members`, { email, displayName: 'Chain Client' });
		expect(m.success).toBeTruthy();

		try {
			// c7: hold ONLY the start view's two fetches; navigate to chat first.
			const startGate = msgGate(page, (u) =>
				u.includes(`/portal/api/orgs/${org.id}/contact`) || u.includes(`/portal/api/orgs/${org.id}/todo`));
			await portalLogin(page, email);
			// boot readiness: viewStart paints its h1 synchronously once init()
			// resolved — tab clicks before READY are dropped by route()
			await page.locator('#kzc-view h1').waitFor({ state: 'visible', timeout: 20000 });
			await page.locator('.kzc-tab[data-view="chat"]').click();
			await page.locator('#kzc-cf').waitFor({ state: 'visible', timeout: 20000 });
			await startGate.release();
			await page.waitForTimeout(600);
			// The stale start continuation must not have repainted over chat.
			await expect(page.locator('#kzc-cf')).toBeVisible();
			await expect(page.locator('#kzc-msgs')).toBeAttached();
			await expect(page.locator('.kzc-stack')).toHaveCount(0);

			// c8: hold the chat GET, send twice — one pending fetch only.
			const chatGate = msgGate(page, (u) => u.includes(`/portal/api/orgs/${org.id}/messages`));
			for (const body of [`p-fork-a-${tag}`, `p-fork-b-${tag}`]) {
				const posted = page.waitForResponse(
					(r) => r.url().includes(`/portal/api/orgs/${org.id}/messages`) && r.request().method() === 'POST',
					{ timeout: 20000 });
				await page.locator('#kzc-cm').fill(body);
				await page.locator('#kzc-cm').press('Enter');
				await posted;
			}
			await page.waitForTimeout(500);
			expect(chatGate.state.pending.length).toBe(1);
			expect(chatGate.state.count).toBe(1);

			await chatGate.release();
			const rows = page.locator('#kzc-msgs .kzc-msg');
			await expect(rows).toHaveCount(2, { timeout: 20000 });
			for (const body of [`p-fork-a-${tag}`, `p-fork-b-${tag}`]) {
				await expect(page.locator('.kzc-msg__body', { hasText: body })).toHaveCount(1);
			}
			await page.waitForTimeout(600);
			expect(chatGate.state.count).toBe(2);
		} finally {
			try { await api('POST', `/api/portal/orgs/${org.id}/offboard`, {}); } catch { /* best effort */ }
			db("DELETE m FROM oc_kzc_member m JOIN oc_kzc_identity i ON m.identity_id=i.id " +
				"WHERE i.email LIKE 'kzc-chain-%@test.invalid';");
			db("DELETE FROM oc_kzc_identity WHERE email LIKE 'kzc-chain-%@test.invalid';");
		}
	});
});

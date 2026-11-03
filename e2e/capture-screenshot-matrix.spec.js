// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/portalcheck/craft/web/. The companion
 * gate check-screenshot-matrix.py verifies completeness.
 *
 * States:
 *   populated — real seeded data visible (atlas fixtures/orgs already in
 *               the dev instance; populated staff views)
 *   current   — state-invariant chrome (login, 404, settings)
 *
 * Staff surfaces need an app-admin login (tests/e2e/.env). Staff views are
 * one SPA — `nav` selects .ptl-navbtn[data-v] after boot.
 *
 * Run: npx playwright test e2e/capture-screenshot-matrix.spec.js
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = '/index.php/apps/portalcheck';
const HERE = __dirname;
const MANIFEST = JSON.parse(fs.readFileSync(path.join(HERE, 'screenshot-matrix.json'), 'utf8'));
const OUT = path.resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/portalcheck', MANIFEST.outDir);
const authed = hasCreds();
fs.mkdirSync(OUT, { recursive: true });

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

async function visitStaff(page, surface) {
	await login(page, creds());
	// deep-link boot param (same ?view= mechanism notification mails use) —
	// nav buttons collapse into a menu at mobile widths and can't be clicked.
	const target = surface.nav ? `/?view=${surface.nav}` : '/';
	await page.goto(`${APP}${target}`, { waitUntil: 'domcontentloaded' });
	await page.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
	await page.waitForTimeout(1200);
}

test.describe('screenshot matrix', () => {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				test(`${surface.id} ${state} ${vp.name}`, async ({ page }) => {
					test.skip(surface.staff && !authed, 'no creds');
					await page.setViewportSize({ width: vp.width, height: vp.height });
					if (surface.staff) {
						await visitStaff(page, surface);
					} else {
						await page.goto(`${APP}${surface.path}`, { waitUntil: 'domcontentloaded' });
						await page.waitForTimeout(800);
					}
					await shot(page, `${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}`);
				});
			}
		}
		if (surface.dark) {
			const state = surface.states[0];
			test(`${surface.id} ${state} desktop dark`, async ({ page }) => {
				test.skip(surface.staff && !authed, 'no creds');
				await page.emulateMedia({ colorScheme: 'dark' });
				await page.setViewportSize({ width: 1280, height: 800 });
				if (surface.staff) {
					await visitStaff(page, surface);
				} else {
					await page.goto(`${APP}${surface.path}`, { waitUntil: 'domcontentloaded' });
					await page.waitForTimeout(800);
				}
				await shot(page, `${MANIFEST.prefix}-${surface.id}-${state}-desktop-dark`);
			});
		}
	}
});

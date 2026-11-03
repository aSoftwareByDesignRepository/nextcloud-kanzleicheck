// @ts-check
// Lens C proof: axe on auth'd staff SPA surfaces + horizontal-scroll check
// at every target viewport. Run: node e2e/axe-staff-scan.js
const { chromium } = require('@playwright/test');
const { AxeBuilder } = require('/home/alex/Development/nextcloud-dev/nextcloud/apps/bookingcheck/node_modules/@axe-core/playwright');
const { login, hasCreds, creds } = require('../tests/e2e/helpers/auth.js');

const APP = 'http://127.0.0.1:8081/index.php/apps/portalcheck';
const VIEWS = [null, 'board', 'requests', 'orgs', 'uploads', 'settings'];
const VIEWS_VP = [
  ['mobile', { width: 375, height: 812 }],
  ['tablet', { width: 768, height: 1024 }],
  ['desktop', { width: 1280, height: 800 }],
];

(async () => {
  if (!hasCreds()) { console.log('SKIP — no creds'); process.exit(0); }
  const b = await chromium.launch();

  // 1) axe per view on desktop (light) + one dark pass on overview
  for (const [scheme] of [['light'], ['dark'], ['hc']]) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 800 }, baseURL: 'http://127.0.0.1:8081' });
    const p = await ctx.newPage();
    if (scheme === 'dark') await p.emulateMedia({ colorScheme: 'dark' });
    if (scheme === 'hc') await p.emulateMedia({ forcedColors: 'active' });
    await login(p, creds());
    for (const v of scheme === 'light' ? VIEWS : scheme === 'hc' ? ['orgs','settings'] : [null]) {
      await p.goto(`${APP}/${v ? `?view=${v}` : ''}`, { waitUntil: 'domcontentloaded' });
      await p.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
      await p.waitForTimeout(900);
      const r = await new AxeBuilder({ page: p }).withTags(['wcag2a', 'wcag2aa']).analyze();
      console.log(`staff-${v || 'overview'}-${scheme} violations=${r.violations.length} ${JSON.stringify(r.violations.map(x => x.id))}`);
    }
    await ctx.close();
  }

  // 2) horizontal-scroll check across breakpoints × all views
  for (const [name, vp] of VIEWS_VP) {
    const ctx = await b.newContext({ viewport: vp, baseURL: 'http://127.0.0.1:8081' });
    const p = await ctx.newPage();
    await login(p, creds());
    for (const v of VIEWS) {
      await p.goto(`${APP}/${v ? `?view=${v}` : ''}`, { waitUntil: 'domcontentloaded' });
      await p.locator('#ptl-staff-view .ptl-nav').waitFor({ state: 'visible', timeout: 20000 });
      await p.waitForTimeout(700);
      const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      console.log(`scroll ${name} staff-${v || 'overview'} overflow=${overflow}px ${overflow > 0 ? 'FAIL' : 'ok'}`);
    }
    await ctx.close();
  }
  await b.close();
})();

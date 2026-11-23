const { chromium } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');
const { login, creds } = require('../tests/e2e/helpers/auth.js');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 }, baseURL: 'http://127.0.0.1:8081' });
  const p = await ctx.newPage();
  await login(p, creds());
  await p.goto('/index.php/apps/kanzleicheck/settings/general', { waitUntil: 'domcontentloaded' });
  await p.locator('#kzc-staff-view #kzc-body').waitFor({ state: 'visible', timeout: 20000 });
  await p.waitForTimeout(900);
  const r = await new AxeBuilder({ page: p }).withTags(['wcag2a', 'wcag2aa']).analyze();
  console.log('staff-settings violations=' + r.violations.length);
  for (const v of r.violations) for (const n of v.nodes) console.log(' -', v.id, '→', n.html.slice(0,200));
  await b.close();
})();

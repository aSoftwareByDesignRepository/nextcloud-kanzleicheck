const { chromium } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  const targets = [
    ['portal-login-light', 'http://127.0.0.1:8081/apps/kanzleicheck/portal/login', {}],
    ['portal-login-dark', 'http://127.0.0.1:8081/apps/kanzleicheck/portal/login', { colorScheme: 'dark' }],
    ['portal-login-hc', 'http://127.0.0.1:8081/apps/kanzleicheck/portal/login', { forcedColors: 'active' }],
    ['portal-notfound', 'http://127.0.0.1:8081/apps/kanzleicheck/portal/u/does-not-exist', {}],
  ];
  for (const [name, url, media] of targets) {
    if (media.colorScheme) await p.emulateMedia({ colorScheme: media.colorScheme });
    if (media.forcedColors) await p.emulateMedia({ forcedColors: media.forcedColors });
    await p.goto(url, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(700);
    const r = await new AxeBuilder({ page: p }).withTags(['wcag2a', 'wcag2aa']).analyze();
    console.log(name, 'violations=' + r.violations.length, JSON.stringify(r.violations.map(v => v.id)));
    await p.emulateMedia({ colorScheme: 'light', forcedColors: 'none' });
  }
  await b.close();
})();

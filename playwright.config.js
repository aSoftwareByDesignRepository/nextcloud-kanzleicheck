const fs = require('fs');
const path = require('path');
const { defineConfig } = require('@playwright/test');

const envFile = path.join(__dirname, 'tests', 'e2e', '.env');
if (fs.existsSync(envFile)) {
	for (const line of fs.readFileSync(envFile, 'utf8').split('\n')) {
		const t = line.trim();
		if (!t || t.startsWith('#')) continue;
		const eq = t.indexOf('=');
		if (eq <= 0) continue;
		const k = t.slice(0, eq).trim();
		let v = t.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
		if (process.env[k] === undefined) process.env[k] = v;
	}
}

// Specs that mutate the shared fixture user's `core lang`. They MUST run after
// every other spec: while a mutator holds lang=pl/nl/pt_BR on the shared user,
// all concurrently rendering pages would paint that locale — no lock can fix
// readers. Project dependency sequences them after the main run instead.
const LOCALE_MUTATORS = ['e2e/l10n-sweep-rendered.spec.js', 'e2e/transfer-plural-verdict.spec.js'];

module.exports = defineConfig({
	testDir: '.',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	use: {
		headless: true,
		baseURL: (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, ''),
		screenshot: 'only-on-failure',
	},
	reporter: [['list']],
	projects: [
		{
			name: 'main',
			testMatch: ['tests/e2e/**/*.spec.js', 'e2e/**/*.spec.js'],
			testIgnore: LOCALE_MUTATORS,
		},
		{ name: 'locale-mutators', testMatch: LOCALE_MUTATORS, dependencies: ['main'] },
	],
});

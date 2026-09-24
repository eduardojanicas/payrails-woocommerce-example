import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests in a real browser. One shared SQLite store and one PHP server, so
 * workers = 1. Each spec switches the server into the mode it needs
 * (tests/e2e/helpers/wp.ts ensureServer): live (Payrails staging) or authfail.
 *
 *   npm run test:e2e:staging   real Payrails staging, with test cards (override via E2E_CARD_*)
 *                              (card, 3DS complete/fail, decline + retry, tampering)
 *   npm run test:e2e:authfail  unreachable API + missing secrets file (no Payrails traffic);
 *                              restores wp-config afterwards
 *   npm run test:e2e           both, in that order
 *
 * PORT (default 8080) or SITE_URL selects the store. E2E_RESET=1 runs scripts/reset.sh
 * first (wipes orders).
 */
export default defineConfig({
	testDir: 'tests/e2e',
	outputDir: 'test-results',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 120_000,
	expect: { timeout: 15_000 },
	reporter: [['list'], ['json', { outputFile: 'tests/e2e/artifacts/results.json' }]],
	globalSetup: './tests/e2e/global-setup.ts',
	globalTeardown: './tests/e2e/global-teardown.ts',
	use: {
		baseURL: process.env.SITE_URL || `http://localhost:${process.env.PORT || '8080'}`,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		viewport: { width: 1280, height: 1000 },
		...devices['Desktop Chrome'],
	},
	projects: [
		{ name: 'staging', testMatch: /staging\/.*\.spec\.ts/ },
		{ name: 'authfail', testMatch: /authfail\/.*\.spec\.ts/ },
	],
});

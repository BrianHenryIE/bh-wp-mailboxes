/**
 * Playwright configuration for bh-wp-mailboxes end-to-end tests.
 *
 * Tests run against a wp-env site (default http://localhost:8886). The development-plugin supplies
 * REST endpoints so tests arrange/assert via REST and touch the UI only for the part actually under
 * test. Two browser projects share them: `chromium` signed in as the administrator, and `editor`
 * signed in as an Editor (only `*.editor.spec.ts` specs).
 *
 * @see https://playwright.dev/docs/test-configuration
 */
import { defineConfig, devices } from '@playwright/test';

require( 'dotenv' ).config();

const WP_BASE_URL =
	process.env.BASEURL || process.env.WP_BASE_URL || 'http://localhost:8886';

// So @wordpress/e2e-test-utils-playwright uses the same base URL.
process.env.WP_BASE_URL = WP_BASE_URL;

export default defineConfig( {
	testDir: './tests/e2e-pw/specs',
	globalSetup: require.resolve( './tests/e2e-pw/global-setup' ),
	globalTeardown: require.resolve( './tests/e2e-pw/global-teardown' ),
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: process.env.CI ? 1 : undefined,
	reporter: 'html',
	timeout: 30_000,
	use: {
		baseURL: WP_BASE_URL,
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'setup',
			testDir: './tests/e2e-pw/setup',
			testMatch: /.*\.setup\.ts/,
		},
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: 'tests/e2e-pw/.auth/user.json',
			},
			dependencies: [ 'setup' ],
			testIgnore: /.*\.editor\.spec\.ts/,
		},
		// Signed in as an Editor (see setup/editor.setup.ts), to prove the screens show only the
		// controls the user may use. Only `*.editor.spec.ts` specs run here.
		{
			name: 'editor',
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: 'tests/e2e-pw/.auth/editor.json',
			},
			dependencies: [ 'setup' ],
			testMatch: /.*\.editor\.spec\.ts/,
		},
	],
} );

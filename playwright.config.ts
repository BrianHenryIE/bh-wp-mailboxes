/**
 * Playwright configuration for bh-wp-mailboxes end-to-end tests.
 *
 * Tests run against a wp-env site (default http://localhost:8888). The development-plugin supplies
 * REST endpoints and a `?login_as_user=` shortcut so tests arrange/assert via REST and touch the UI
 * only for the part actually under test.
 *
 * @see https://playwright.dev/docs/test-configuration
 */
import { defineConfig, devices } from '@playwright/test';

require( 'dotenv' ).config();

const WP_BASE_URL =
	process.env.BASEURL || process.env.WP_BASE_URL || 'http://localhost:8888';

// So @wordpress/e2e-test-utils-playwright uses the same base URL.
process.env.WP_BASE_URL = WP_BASE_URL;

export default defineConfig( {
	testDir: './tests/e2e-pw/specs',
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
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );

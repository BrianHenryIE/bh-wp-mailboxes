/**
 * Global authentication setup for Playwright tests.
 *
 * Logs in through the normal `wp-login.php` form (no development-plugin shortcut) and saves the session
 * cookies to tests/e2e-pw/.auth/user.json so every test starts authenticated. Credentials default to the
 * wp-env admin (admin / password) and can be overridden via WP_ADMIN_USER / WP_ADMIN_PASSWORD.
 */
import { test as setup } from '@wordpress/e2e-test-utils-playwright';
import path from 'path';
import fs from 'fs';

const AUTH_FILE = path.join( __dirname, '../.auth/user.json' );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

setup( 'authenticate', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASSWORD );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

	fs.mkdirSync( path.dirname( AUTH_FILE ), { recursive: true } );
	await page.context().storageState( { path: AUTH_FILE } );
} );

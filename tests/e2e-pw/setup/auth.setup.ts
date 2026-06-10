/**
 * Global authentication setup for Playwright tests.
 *
 * Uses the development-plugin's `?login_as_user=<slug>` shortcut to authenticate
 * the browser context without going through the WP login UI. Saves session cookies
 * to tests/e2e-pw/.auth/user.json so all tests start authenticated.
 *
 * @see development-plugin/class-authentication.php
 */
import { test as setup } from '@wordpress/e2e-test-utils-playwright';
import path from 'path';
import fs from 'fs';

const AUTH_FILE = path.join( __dirname, '../.auth/user.json' );

setup( 'authenticate', async ( { page } ) => {
	await page.goto( '/?login_as_user=admin' );
	fs.mkdirSync( path.dirname( AUTH_FILE ), { recursive: true } );
	await page.context().storageState( { path: AUTH_FILE } );
} );

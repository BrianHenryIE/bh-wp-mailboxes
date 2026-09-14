/**
 * Authentication setup for the "editor" Playwright project.
 *
 * Asks the development plugin for a user with the Editor role (created, or its password reset, by
 * `POST /bh-wp-mailboxes-dev/v2/users`), signs in through the normal `wp-login.php` form and saves the
 * session cookies to tests/e2e-pw/.auth/editor.json. Specs matching `*.editor.spec.ts` run as this user
 * to prove the admin screens show only the controls a user may use.
 */
import { test as setup, expect } from '@wordpress/e2e-test-utils-playwright';
import path from 'path';
import fs from 'fs';

const AUTH_FILE = path.join( __dirname, '../.auth/editor.json' );
const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v2';

setup( 'authenticate as an editor', async ( { page, request } ) => {
	const res = await request.post( `${ DEV_REST }/users`, { data: { role: 'editor' } } );
	expect( res.status() ).toBe( 201 );
	const { login, password } = await res.json();

	// Land on the profile screen rather than the dashboard: the dashboard's Activity widget checks
	// `edit_post` on every recent comment's post, and a local database can hold posts of mailboxes that
	// are no longer registered, which core reports as a notice in debug.log (the global teardown fails on it).
	await page.goto( `/wp-login.php?redirect_to=${ encodeURIComponent( '/wp-admin/profile.php' ) }` );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', password );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

	fs.mkdirSync( path.dirname( AUTH_FILE ), { recursive: true } );
	await page.context().storageState( { path: AUTH_FILE } );
} );

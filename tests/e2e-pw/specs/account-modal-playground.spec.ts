/**
 * Playwright tests for the add-account dialog's WordPress Playground warning.
 *
 * Playground runs PHP as WebAssembly in the browser, which cannot open network sockets, so an IMAP account
 * can be saved there but can never check for email. The dialog says so at the top.
 *
 * These tests run on wp-env, so Playground is simulated with the dev REST `POST /simulate-playground` flag
 * (it hooks the library's `bh_wp_mailboxes_is_wordpress_playground` filter).
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

// The simulation flag is a single global option: run this file's tests one at a time.
test.describe.configure( { mode: 'serial' } );

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v2';
const EMAILS_LIST = 'post_type=e2e_email';
const PLAYGROUND_WARNING =
	'This site is running in WordPress Playground, which cannot connect to mail servers. You can save this account, but it will not be able to check for email, and "Test connection" will fail.';

async function setSimulatePlayground( request: Parameters< typeof test >[ 1 ][ 'request' ], enabled: boolean ) {
	const response = await request.post( `${ DEV_REST }/simulate-playground`, { data: { enabled } } );
	expect( response.status() ).toBe( 200 );
}

async function openAddDialog( page: Page ) {
	await page.getByRole( 'button', { name: 'Add account' } ).click();
	const dialog = page.locator( '#bh-mailboxes-account-dialog' );
	await expect( dialog ).toBeVisible();
	return dialog;
}

test.describe( 'Add-account dialog in WordPress Playground', () => {
	test.afterEach( async ( { request } ) => {
		await setSimulatePlayground( request, false );
	} );

	test( 'warns at the top that email cannot be checked, and still saves the account', async ( {
		admin,
		page,
		request,
		requestUtils,
	} ) => {
		await setSimulatePlayground( request, true );

		const emailAddress = `playground-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );

		const dialog = await openAddDialog( page );

		const warning = dialog.locator( '.bh-mailboxes-account-form__playground-notice' );
		await expect( warning ).toBeVisible();
		await expect( warning ).toHaveText( PLAYGROUND_WARNING );

		// At the top: above the first field.
		const warningBox = await warning.boundingBox();
		const firstFieldBox = await dialog.getByLabel( 'Account name' ).boundingBox();
		expect( warningBox!.y ).toBeLessThan( firstFieldBox!.y );

		// Saving still works (here the server is unreachable, as every server is from Playground).
		await dialog.getByLabel( 'Account name' ).fill( 'Playground inbox' );
		await dialog.getByLabel( 'Email address' ).fill( emailAddress );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await dialog.getByLabel( 'Encryption' ).selectOption( '' );
		await dialog.getByRole( 'button', { name: 'Add account' } ).click();

		await expect( dialog ).toBeHidden();
		const row = page.locator( `.bh-mailboxes-account[data-email-address="${ emailAddress }"]` );
		await expect( row ).toBeVisible();
		await expect( row ).toContainText( 'Playground inbox' );

		// Clean up the account.
		const accountId = await row.getAttribute( 'data-account-id' );
		expect( accountId ).toBeTruthy();
		await requestUtils.rest( {
			path: `/bh-wp-mailboxes-dev/v2/e2e-accounts/${ accountId }`,
			method: 'DELETE',
		} );
	} );

	test( 'has no Playground warning elsewhere', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );

		const dialog = await openAddDialog( page );

		await expect( dialog.getByLabel( 'Account name' ) ).toBeVisible();
		await expect( dialog.locator( '.bh-mailboxes-account-form__playground-notice' ) ).toHaveCount( 0 );
	} );
} );

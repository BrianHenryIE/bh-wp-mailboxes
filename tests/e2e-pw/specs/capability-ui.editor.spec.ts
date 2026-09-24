/**
 * The admin screens show only the controls the signed-in user may use.
 *
 * Runs as an Editor (the `editor` project). The development plugin grants editors access to the e2e
 * mailbox through the library's `bh_wp_mailboxes_required_capability` filter, at a level set here via
 * its REST route: "edit" (act on emails, not on accounts) hides the account controls; "manage" shows
 * them; no level at all keeps the editor out of the screens entirely.
 *
 * The tests change one shared setting, so they run one at a time.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v2';
const LIST_PAGE = 'post_type=e2e_email';

test.describe.configure( { mode: 'serial' } );

async function setEditorAccess( request: APIRequestContext, level: '' | 'read' | 'edit' | 'manage' ): Promise< void > {
	const res = await request.post( `${ DEV_REST }/editor-access`, { data: { level } } );
	expect( res.status() ).toBe( 200 );
}

async function createAccount( request: APIRequestContext ): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: `editor-${ Date.now() }@example.com`, display_name: 'Editor account' },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

async function createEmail( request: APIRequestContext, accountId: number ): Promise< number > {
	const res = await request.post( `${ DEV_REST }/emails`, {
		data: { subject: `Editor e2e ${ Date.now() }`, account_id: accountId, is_read: false },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

test.describe( 'Capability-aware UI (as an Editor)', () => {
	// afterAll has no per-test request context: reset the shared setting with a plain fetch.
	test.afterAll( async () => {
		const baseUrl = process.env.WP_BASE_URL || process.env.BASEURL || 'http://localhost:8886';
		await fetch( `${ baseUrl }${ DEV_REST }/editor-access`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { level: '' } ),
		} );
	} );

	test( 'with no access, the emails list is refused', async ( { page, request } ) => {
		await setEditorAccess( request, '' );

		await page.goto( `/wp-admin/edit.php?${ LIST_PAGE }` );

		await expect( page.locator( 'body#error-page' ) ).toContainText( 'not allowed' );
	} );

	test( 'with "edit" access, the list shows email actions but no account controls', async ( {
		admin,
		page,
		request,
	} ) => {
		await setEditorAccess( request, 'edit' );
		const accountId = await createAccount( request );
		const emailId = await createEmail( request, accountId );

		await admin.visitAdminPage( 'edit.php', `${ LIST_PAGE }&bh_email_account=${ accountId }` );

		// The screen itself is reachable.
		await expect( page.locator( 'h1.wp-heading-inline' ) ).toBeVisible();

		// Account controls: none. Neither the "Check now" button, the accounts table, "Add account", nor the modal.
		await expect( page.locator( '#check-email' ) ).toHaveCount( 0 );
		await expect( page.locator( '#bh-mailboxes-status' ) ).toHaveCount( 0 );
		await expect( page.locator( '.bh-account-add' ) ).toHaveCount( 0 );
		await expect( page.locator( '#bh-mailboxes-account-dialog' ) ).toHaveCount( 0 );

		// Email actions: the account filter and "Delete on server" are offered.
		await expect( page.locator( '#bh_email_account' ) ).toBeAttached();
		await expect( page.locator( `#post-${ emailId } .bh-email-delete-on-server` ) ).toBeAttached();
	} );

	test( 'with "edit" access, the single email view offers Save, Update and Delete on server', async ( {
		admin,
		page,
		request,
	} ) => {
		await setEditorAccess( request, 'edit' );
		const emailId = await createEmail( request, await createAccount( request ) );

		await admin.visitAdminPage( 'post.php', `post=${ emailId }&action=edit` );

		await expect( page.locator( '#bh-email-local-status input[name="post_status"]' ).first() ).toBeAttached();
		await expect( page.locator( '#bh-email-status-box #save' ) ).toBeVisible();
		await expect( page.locator( '#bh-email-remote-save' ) ).toBeVisible();
		await expect( page.locator( '#bh-email-delete-on-server' ) ).toBeAttached();
	} );

	test( 'with "manage" access, the account controls appear', async ( { admin, page, request } ) => {
		await setEditorAccess( request, 'manage' );
		await createAccount( request );

		await admin.visitAdminPage( 'edit.php', LIST_PAGE );

		await expect( page.locator( '#check-email' ) ).toBeVisible();
		await expect( page.locator( '#bh-mailboxes-status' ) ).toBeVisible();
		await expect( page.locator( '.bh-account-add' ) ).toBeVisible();
		await expect( page.locator( '#bh-mailboxes-account-dialog' ) ).toBeAttached();
	} );
} );

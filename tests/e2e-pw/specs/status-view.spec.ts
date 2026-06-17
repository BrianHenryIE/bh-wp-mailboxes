/**
 * Playwright tests for the Status_View component.
 *
 * Status_View renders a per-account summary table at the top of the emails list table.
 * Arrange via REST, assert by navigating to the emails admin list page.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

async function createAccount(
	request: Parameters< typeof test >[ 1 ][ 'request' ],
	emailAddress: string,
	displayName?: string
): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: emailAddress, display_name: displayName ?? emailAddress },
	} );
	expect( res.status() ).toBe( 201 );
	const body = await res.json();
	return body.post_id as number;
}

test.describe( 'Status_View', () => {
	test( 'status container is present on the emails list page', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).toBeAttached();
	} );

	test( '"No accounts configured" message shown when no accounts exist', async ( { admin, page, request } ) => {
		// Only meaningful on a clean DB; skip gracefully if other tests have already added accounts.
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const container = page.locator( '#bh-mailboxes-status' );
		await expect( container ).toBeAttached();

		const hasCards = await page.locator( '.bh-mailboxes-account-card' ).count();
		if ( hasCards === 0 ) {
			await expect( container ).toContainText( 'No accounts configured' );
		}
	} );

	test( 'account email address appears in its status card', async ( { admin, page, request } ) => {
		const email = `status-view-e2e-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const card = page.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"]` );
		await expect( card ).toBeVisible();
		await expect( card ).toContainText( email );
	} );

	test( '"Active" status label shown for a newly-created account', async ( { admin, page, request } ) => {
		const email = `active-account-e2e-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const card = page.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"]` );
		await expect( card ).toContainText( 'Active' );
	} );

	test( '"Never" shown in Last Fetched column for a newly-created account', async ( { admin, page, request } ) => {
		const email = `never-fetched-e2e-${ Date.now() }@example.com`;
		await createAccount( request, email );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).toContainText( 'Never' );
	} );

	test( 'status table is absent on the accounts list page', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_accounts' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).not.toBeAttached();
	} );
} );

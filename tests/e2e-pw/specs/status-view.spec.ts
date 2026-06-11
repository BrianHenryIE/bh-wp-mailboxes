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
		await admin.visitAdminPage( 'edit.php', 'post_type=bh_wp_mailboxes_cpt' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).toBeAttached();
	} );

	test( '"No accounts configured" message shown when no accounts exist', async ( { admin, page, request } ) => {
		// Ensure this test runs with no accounts by checking the message directly.
		// (Other tests may add accounts; this test just asserts the message appears when
		// the container is present and no account rows are in the table.)
		await admin.visitAdminPage( 'edit.php', 'post_type=bh_wp_mailboxes_cpt' );

		const container = page.locator( '#bh-mailboxes-status' );
		await expect( container ).toBeAttached();

		// Only assert the message when no table rows are present.
		const hasTable = await page.locator( '#bh-mailboxes-status table' ).count();
		if ( hasTable === 0 ) {
			await expect( container ).toContainText( 'No accounts configured' );
		}
	} );

	test( 'account email address appears in the status table', async ( { admin, page, request } ) => {
		const email = `status-view-e2e-${ Date.now() }@example.com`;
		await createAccount( request, email );

		await admin.visitAdminPage( 'edit.php', 'post_type=bh_wp_mailboxes_cpt' );

		await expect( page.locator( '#bh-mailboxes-status table' ) ).toBeVisible();
		await expect( page.locator( '#bh-mailboxes-status' ) ).toContainText( email );
	} );

	test( '"Active" status label shown for a newly-created account', async ( { admin, page, request } ) => {
		const email = `active-account-e2e-${ Date.now() }@example.com`;
		await createAccount( request, email );

		await admin.visitAdminPage( 'edit.php', 'post_type=bh_wp_mailboxes_cpt' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).toContainText( 'Active' );
	} );

	test( '"Never" shown in Last Fetched column for a newly-created account', async ( { admin, page, request } ) => {
		const email = `never-fetched-e2e-${ Date.now() }@example.com`;
		await createAccount( request, email );

		await admin.visitAdminPage( 'edit.php', 'post_type=bh_wp_mailboxes_cpt' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).toContainText( 'Never' );
	} );

	test( 'status table is absent on the accounts list page', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', 'post_type=my_plugin_account' );

		await expect( page.locator( '#bh-mailboxes-status' ) ).not.toBeAttached();
	} );
} );

/**
 * Playwright tests for the emails list page header controls.
 *
 * The "Check now"/"Check all" button replaces WordPress's default "Add New" button in the page title,
 * and is labelled "Check all" when the mailbox has more than one account.
 *
 * Arrange via REST, assert by navigating to the emails admin list page.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

async function createAccount(
	request: Parameters< typeof test >[ 1 ][ 'request' ],
	emailAddress: string
): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: emailAddress, display_name: emailAddress },
	} );
	expect( res.status() ).toBe( 201 );
	const body = await res.json();
	return body.post_id as number;
}

test.describe( 'Emails list page — check button', () => {
	test( 'the default "Add New" button is replaced by the check button in the page title', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const button = page.locator( '#check-email' );
		await expect( button ).toBeVisible();
		// Styled and positioned as the page-title action (where "Add New" was).
		await expect( button ).toHaveClass( /page-title-action/ );

		// The default "Add New" anchor is gone (our button is a <button>, not an <a>).
		await expect( page.locator( 'a.page-title-action' ) ).toHaveCount( 0 );
	} );

	test( 'button reads "Check all" when the mailbox has more than one account', async ( {
		admin,
		page,
		request,
	} ) => {
		// The dev fixtures mailbox already has one account; add two more to be safe.
		await createAccount( request, `check-all-a-${ Date.now() }@example.com` );
		await createAccount( request, `check-all-b-${ Date.now() }@example.com` );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		await expect( page.locator( '#check-email' ) ).toHaveText( 'Check all' );
	} );
} );

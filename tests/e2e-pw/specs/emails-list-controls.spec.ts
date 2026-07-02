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
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

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

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		await expect( page.locator( '#check-email' ) ).toHaveText( 'Check all' );
	} );

	test( 'the list table has a "Sent" column to the left of the "Date" column', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		await expect( page.locator( 'thead th#sent' ) ).toHaveText( 'Sent' );

		// "Sent" must come before the standard "Date" column.
		const headerIds = await page
			.locator( 'thead#the-list-head th, thead th' )
			.evaluateAll( ( els ) => els.map( ( el ) => el.id ).filter( Boolean ) );
		expect( headerIds.indexOf( 'sent' ) ).toBeGreaterThan( -1 );
		expect( headerIds.indexOf( 'sent' ) ).toBeLessThan( headerIds.indexOf( 'date' ) );
	} );
} );

test.describe( 'Emails list page — row actions', () => {
	test( '"Trash" is relabelled "Trash locally" and "Delete on server" is offered with confirmation', async ( {
		admin,
		page,
		request,
	} ) => {
		// The fixtures connection supports delete-on-server; fetch an email so a row links to its account.
		const accountId = await createAccount(
			request,
			`row-delete-${ Date.now() }@example.com`
		);

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const checkResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'admin-ajax.php' ) &&
				( res.request().postData() ?? '' ).includes(
					`account_post_id=${ accountId }`
				)
		);
		await page
			.locator( `.bh-check-account[data-account-id="${ accountId }"]` )
			.click( { force: true } );
		const checkBody = await ( await checkResponse ).json();
		const emailId = checkBody.data.new_email_ids[ 0 ] as number;
		expect( emailId ).toBeTruthy();

		// Reload the list so the fetched email's row is present.
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const row = page.locator( `#post-${ emailId }` );
		await expect( row ).toBeAttached();

		// "Trash" became "Trash locally".
		await expect( row.locator( '.row-actions' ) ).toContainText( 'Trash locally' );

		// "Delete on server" is offered, coloured the same red as the core "Trash" action.
		const deleteOnServer = row.locator( '.bh-email-delete-on-server' );
		await expect( deleteOnServer ).toBeAttached();
		await expect( deleteOnServer ).toHaveCSS( 'color', 'rgb(179, 45, 46)' );

		// Clicking shows a confirm() modal; accept it and confirm the delete request is sent. The row
		// actions are hidden off-screen until hover, so dispatch the click directly to the delegated
		// handler rather than relying on a viewport-positioned click.
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		const deleteResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'admin-ajax.php' ) &&
				( res.request().postData() ?? '' ).includes( `post_id=${ emailId }` )
		);
		await deleteOnServer.dispatchEvent( 'click' );
		await deleteResponse;

		// The table reloads; once deleted on the server, the row no longer offers "Delete on server".
		await expect(
			page.locator( `#post-${ emailId } .bh-email-delete-on-server` )
		).toHaveCount( 0, { timeout: 10_000 } );
	} );
} );

/**
 * Playwright tests for Status_View interactive behaviours.
 *
 * Covers: "Check now" notice lifecycle, the "Check since…" dialog, and card in-place updates.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v2';

async function createAccount(
	request: Parameters< typeof test >[ 1 ][ 'request' ],
	emailAddress: string
): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: emailAddress, display_name: emailAddress },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

/** Waits for the account's REST check response (`POST …/{accounts}/{id}/check`). */
function waitForCheckResponse( page: Page, accountId: number ) {
	return page.waitForResponse( ( res ) => res.url().includes( `/${ accountId }/check` ) );
}

/** Delays the account's REST check request so the in-progress UI state can be asserted. */
async function delayCheckRequest( page: Page, accountId: number, ms: number ) {
	await page.route( `**/${ accountId }/check`, async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, ms ) );
		await route.continue();
	} );
}

/** Row actions are revealed on row hover (WP_List_Table's `.row-actions`): hover the account row, then click "Check now". */
async function clickCheckNow( page: Page, accountId: number ) {
	const row = page.locator( `.bh-mailboxes-account[data-account-id="${ accountId }"]` );
	await row.hover();
	await row.locator( '.bh-check-account' ).click();
}

test.describe( 'Status_View — Check now button', () => {
	test( 'Check now updates the email count, unprocessed suffix and lifetime line in place', async ( { admin, page, request } ) => {
		const postId = await createAccount( request, `count-inplace-${ Date.now() }@example.com` );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const card = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		await expect( card.locator( '[data-field="lifetime"]' ) ).toHaveCount( 0 );

		await clickCheckNow( page, postId );
		await waitForCheckResponse( page, postId );

		// The fixtures connection delivers five emails; none are rejected by filters.
		await expect( card.locator( '[data-field="email-count"]' ) ).toHaveText( '5' );
		await expect( card.locator( '[data-field="email-count-new"]' ) ).toHaveText( ' (5 new)' );
		await expect( card.locator( '[data-field="lifetime"]' ) ).toHaveText( '5 fetched' );

		// Reloading shows the same figures server-rendered.
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );
		await expect( card.locator( '[data-field="email-count-new"]' ) ).toHaveText( ' (5 new)' );
		await expect( card.locator( '[data-field="lifetime"]' ) ).toHaveText( '5 fetched' );
	} );

	test( 'shows grey notice with spinner immediately after click', async ( { admin, page, request } ) => {
		const email = `check-spinner-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		// Delay the request for this account so we can assert the in-progress state.
		await delayCheckRequest( page, postId, 800 );

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );
		await clickCheckNow( page, postId );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice ).toBeVisible();
		await expect( notice.locator( '.spinner.is-active' ) ).toBeVisible();
		await expect( notice ).toContainText( email );

		const borderColor = await notice.evaluate(
			( el ) => window.getComputedStyle( el ).borderLeftColor
		);
		expect( borderColor ).toBe( 'rgb(141, 150, 160)' ); // #8d96a0
	} );

	test( 'notice updates to blue with "no new emails" message after a successful check', async ( { admin, page, request } ) => {
		const email = `check-done-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		// First check saves the fixture emails for this account...
		await clickCheckNow( page, postId );
		await waitForCheckResponse( page, postId );

		// ...so the second check finds them all already saved (deduped) → no new emails.
		await clickCheckNow( page, postId );
		await waitForCheckResponse( page, postId );
		await page.waitForTimeout( 350 ); // CSS transition: border-left-color 0.3s

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice ).toContainText( 'Email checked successfully, no new emails.' );
		await expect( notice.locator( '.spinner' ) ).not.toBeAttached();

		const borderColor = await notice.evaluate(
			( el ) => window.getComputedStyle( el ).borderLeftColor
		);
		expect( borderColor ).toBe( 'rgb(114, 174, 230)' ); // #72aee6
	} );

	test( 'notice is dismissible during the grey (checking) state', async ( { admin, page, request } ) => {
		const email = `dismiss-grey-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		await delayCheckRequest( page, postId, 3000 );

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );
		await clickCheckNow( page, postId );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice.locator( '.notice-dismiss' ) ).toBeVisible();
		await notice.locator( '.notice-dismiss' ).click();
		await expect( notice ).not.toBeVisible();
	} );

	test( 'notice is dismissible after the check completes', async ( { admin, page, request } ) => {
		const email = `dismiss-done-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		await clickCheckNow( page, postId );
		await waitForCheckResponse( page, postId );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice.locator( '.notice-dismiss' ) ).toBeVisible();
		await notice.locator( '.notice-dismiss' ).click();
		await expect( notice ).not.toBeVisible();
	} );

	test( '"Last fetched" updates to "Just now" in the row without a full page reload', async ( { admin, page, request } ) => {
		const email = `last-fetched-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const lastFetched = page
			.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` )
			.locator( '[data-field="last-fetched"]' );
		await expect( lastFetched ).toContainText( 'Never' );

		await clickCheckNow( page, postId );
		await waitForCheckResponse( page, postId );

		await expect( lastFetched ).toContainText( 'Just now' );
		expect( page.url() ).toContain( 'edit.php' );
	} );
} );

test.describe( 'Status_View — Check since… dialog', () => {
	const DIALOG = '#bh-mailboxes-fetch-since';

	/** Opens the dialog for the account and returns its locators. */
	async function openSinceDialog( page: Page, postId: number ) {
		const card = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		// Row actions are revealed on row hover (WP_List_Table's `.row-actions`).
		await card.hover();
		await card.locator( '.bh-fetch-since-toggle' ).click();
		const dialog = page.locator( DIALOG );
		await expect( dialog ).toBeVisible();
		return { card, dialog, input: dialog.locator( '.bh-fetch-since-input' ) };
	}

	test( 'clicking "Check since…" opens a modal without changing the row height', async ( { admin, page, request } ) => {
		const email = `since-open-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const card = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		await expect( page.locator( DIALOG ) ).not.toBeVisible();
		const before = await card.boundingBox();

		const { dialog } = await openSinceDialog( page, postId );

		// Native <dialog> opened with showModal() carries the `open` attribute and a backdrop.
		await expect( dialog ).toHaveAttribute( 'open', '' );
		await expect( dialog ).toContainText( email );
		await expect( dialog ).toContainText( 'This will poll for 100 emails at a time until no more are found.' );
		await expect( dialog ).toContainText( 'Emails already downloaded will be ignored based on their message id.' );
		await expect( dialog.getByRole( 'button', { name: 'Fetch' } ) ).toBeVisible();
		await expect( dialog.getByRole( 'button', { name: 'Cancel' } ) ).toBeVisible();

		const after = await card.boundingBox();
		expect( before ).not.toBeNull();
		expect( after ).not.toBeNull();
		expect( after!.height ).toBe( before!.height );
	} );

	test( 'date input is pre-populated with one week ago for a new account', async ( { admin, page, request } ) => {
		const email = `since-prefill-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const { input } = await openSinceDialog( page, postId );

		const oneWeekAgo = new Date();
		oneWeekAgo.setDate( oneWeekAgo.getDate() - 7 );
		await expect( input ).toHaveValue( oneWeekAgo.toISOString().split( 'T' )[ 0 ] );
	} );

	test( 'Cancel closes the dialog without sending a check', async ( { admin, page, request } ) => {
		const email = `since-cancel-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		let checkRequests = 0;
		await page.route( `**/${ postId }/check`, async ( route ) => {
			checkRequests++;
			await route.continue();
		} );

		const { dialog, input } = await openSinceDialog( page, postId );
		await input.fill( '2026-01-01' );
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();

		await expect( dialog ).not.toBeVisible();
		await expect( page.locator( `.bh-check-notice[data-account-id="${ postId }"]` ) ).toHaveCount( 0 );
		expect( checkRequests ).toBe( 0 );
	} );

	test( 'Fetch closes the dialog and shows grey notice with spinner then resolves', async ( { admin, page, request } ) => {
		const email = `since-change-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		await delayCheckRequest( page, postId, 600 );

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const { dialog, input } = await openSinceDialog( page, postId );
		await input.fill( '2026-01-01' );
		await dialog.getByRole( 'button', { name: 'Fetch' } ).click();

		await expect( dialog ).not.toBeVisible();

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice ).toBeVisible();
		await expect( notice.locator( '.spinner.is-active' ) ).toBeVisible();

		await waitForCheckResponse( page, postId );
		await expect( notice ).toContainText( 'Email checked successfully' );
		await expect( notice.locator( '.spinner' ) ).not.toBeAttached();
	} );

	test( 'newly-fetched email rows are briefly highlighted after a check', async ( { admin, page, request } ) => {
		const email  = `highlight-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		// Filtered to this account so its new rows are on the first page whatever other specs have fetched.
		await admin.visitAdminPage( 'edit.php', `post_type=e2e_email&bh_email_account=${ postId }` );

		// A fresh account's first check fetches the fixture emails as new.
		await clickCheckNow( page, postId );
		await waitForCheckResponse( page, postId );

		// After the table refreshes, the new rows carry the (transient, fading) highlight class.
		await expect( page.locator( '#the-list tr.bh-email-row--new' ).first() ).toBeAttached( { timeout: 5000 } );
	} );

	test( 'set-date check can be triggered more than once per page load with the same date', async ( { admin, page, request } ) => {
		const email = `since-twice-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		for ( let i = 0; i < 2; i++ ) {
			const { dialog, input } = await openSinceDialog( page, postId );
			await input.fill( '2026-01-01' );
			const response = waitForCheckResponse( page, postId );
			await dialog.getByRole( 'button', { name: 'Fetch' } ).click();
			await response;
			await expect( dialog ).not.toBeVisible();
		}
	} );
} );

/**
 * Playwright tests for Status_View interactive behaviours.
 *
 * Covers: "Check now" notice lifecycle, clock/since-date input, and card in-place updates.
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

test.describe( 'Status_View — Check now button', () => {
	test( 'shows grey notice with spinner immediately after click', async ( { admin, page, request } ) => {
		const email = `check-spinner-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		// Delay the request for this account so we can assert the in-progress state.
		await delayCheckRequest( page, postId, 800 );

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );
		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );

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
		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );
		await waitForCheckResponse( page, postId );

		// ...so the second check finds them all already saved (deduped) → no new emails.
		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );
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
		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice.locator( '.notice-dismiss' ) ).toBeVisible();
		await notice.locator( '.notice-dismiss' ).click();
		await expect( notice ).not.toBeVisible();
	} );

	test( 'notice is dismissible after the check completes', async ( { admin, page, request } ) => {
		const email = `dismiss-done-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );
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

		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );
		await waitForCheckResponse( page, postId );

		await expect( lastFetched ).toContainText( 'Just now' );
		expect( page.url() ).toContain( 'edit.php' );
	} );
} );

test.describe( 'Status_View — Since (clock) button', () => {
	test( 'date input is hidden initially and appears below the actions row after clicking clock', async ( { admin, page, request } ) => {
		const email = `clock-toggle-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const card   = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		const input  = card.locator( '.bh-fetch-since-input' );
		await expect( input ).not.toBeVisible();

		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );
		await expect( input ).toBeVisible();

		const actionsBox = await card.locator( '.bh-mailboxes-account__check' ).boundingBox();
		const inputBox   = await input.boundingBox();
		expect( actionsBox ).not.toBeNull();
		expect( inputBox ).not.toBeNull();
		// Input top edge must be at or below the actions div bottom edge.
		expect( inputBox!.y ).toBeGreaterThanOrEqual( actionsBox!.y + actionsBox!.height - 2 );
	} );

	test( 'date input is pre-populated with one week ago for a new account', async ( { admin, page, request } ) => {
		const email = `since-prefill-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const value = await page
			.locator( `.bh-mailboxes-account[data-account-id="${ postId }"] .bh-fetch-since-input` )
			.inputValue();

		const oneWeekAgo = new Date();
		oneWeekAgo.setDate( oneWeekAgo.getDate() - 7 );
		expect( value ).toBe( oneWeekAgo.toISOString().split( 'T' )[ 0 ] );
	} );

	test( 'changing since date shows grey notice with spinner then resolves', async ( { admin, page, request } ) => {
		const email = `since-change-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		await delayCheckRequest( page, postId, 600 );

		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const card  = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );

		const input = card.locator( '.bh-fetch-since-input' );
		await expect( input ).toBeVisible();
		await input.fill( '2026-01-01' );
		await input.dispatchEvent( 'change' );

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
		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );
		await waitForCheckResponse( page, postId );

		// After the table refreshes, the new rows carry the (transient, fading) highlight class.
		await expect( page.locator( '#the-list tr.bh-email-row--new' ).first() ).toBeAttached( { timeout: 5000 } );
	} );

	test( 'set-date check can be triggered more than once per page load', async ( { admin, page, request } ) => {
		const email = `since-twice-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const card  = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		const input = card.locator( '.bh-fetch-since-input' );

		// First set-date check.
		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );
		await expect( input ).toBeVisible();
		await input.fill( '2026-01-01' );
		const first = waitForCheckResponse( page, postId );
		await input.dispatchEvent( 'change' );
		await first;

		// The input is cleared after a check, so re-selecting the same date counts as a change.
		await expect( input ).toHaveValue( '' );

		// Second set-date check — re-open and pick the SAME date. Should fire another request.
		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );
		await expect( input ).toBeVisible();
		await input.fill( '2026-01-01' );
		const second = waitForCheckResponse( page, postId );
		await input.dispatchEvent( 'change' );
		await second;
	} );

	test( 'since input hides after a successful check', async ( { admin, page, request } ) => {
		const email = `since-hide-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=e2e_email' );

		const card  = page.locator( `.bh-mailboxes-account[data-account-id="${ postId }"]` );
		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );

		const input = card.locator( '.bh-fetch-since-input' );
		await input.fill( '2026-01-01' );
		await input.dispatchEvent( 'change' );
		await waitForCheckResponse( page, postId );

		await expect( input ).not.toBeVisible();
	} );
} );

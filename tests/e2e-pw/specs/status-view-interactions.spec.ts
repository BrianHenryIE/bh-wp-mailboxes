/**
 * Playwright tests for Status_View interactive behaviours.
 *
 * Covers: "Check now" notice lifecycle, clock/since-date input, and card in-place updates.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

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

/** Waits for the check_account AJAX response for the given account post ID. */
function waitForCheckResponse( page: Page, accountId: number ) {
	return page.waitForResponse(
		( res ) =>
			res.url().includes( 'admin-ajax.php' ) &&
			( res.request().postData() ?? '' ).includes(
				`account_post_id=${ accountId }`
			)
	);
}

test.describe( 'Status_View — Check now button', () => {
	test( 'shows grey notice with spinner immediately after click', async ( { admin, page, request } ) => {
		const email = `check-spinner-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		// Delay AJAX for this account so we can assert the in-progress state.
		await page.route( '**/admin-ajax.php', async ( route ) => {
			if ( ( route.request().postData() ?? '' ).includes( `account_post_id=${ postId }` ) ) {
				await new Promise( ( r ) => setTimeout( r, 800 ) );
			}
			await route.continue();
		} );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
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
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

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

		await page.route( '**/admin-ajax.php', async ( route ) => {
			if ( ( route.request().postData() ?? '' ).includes( `account_post_id=${ postId }` ) ) {
				await new Promise( ( r ) => setTimeout( r, 3000 ) );
			}
			await route.continue();
		} );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice.locator( '.notice-dismiss' ) ).toBeVisible();
		await notice.locator( '.notice-dismiss' ).click();
		await expect( notice ).not.toBeVisible();
	} );

	test( 'notice is dismissible after the check completes', async ( { admin, page, request } ) => {
		const email = `dismiss-done-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		await page.locator( `.bh-check-account[data-account-id="${ postId }"]` ).click( { force: true } );
		await waitForCheckResponse( page, postId );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ postId }"]` );
		await expect( notice.locator( '.notice-dismiss' ) ).toBeVisible();
		await notice.locator( '.notice-dismiss' ).click();
		await expect( notice ).not.toBeVisible();
	} );

	test( '"Last fetched" updates to "Just now" in the card without a full page reload', async ( { admin, page, request } ) => {
		const email = `last-fetched-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const lastFetched = page
			.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"]` )
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
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const card   = page.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"]` );
		const input  = card.locator( '.bh-fetch-since-input' );
		await expect( input ).not.toBeVisible();

		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );
		await expect( input ).toBeVisible();

		const actionsBox = await card.locator( '.bh-mailboxes-account-card__actions' ).boundingBox();
		const inputBox   = await input.boundingBox();
		expect( actionsBox ).not.toBeNull();
		expect( inputBox ).not.toBeNull();
		// Input top edge must be at or below the actions div bottom edge.
		expect( inputBox!.y ).toBeGreaterThanOrEqual( actionsBox!.y + actionsBox!.height - 2 );
	} );

	test( 'date input is pre-populated with one week ago for a new account', async ( { admin, page, request } ) => {
		const email = `since-prefill-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const value = await page
			.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"] .bh-fetch-since-input` )
			.inputValue();

		const oneWeekAgo = new Date();
		oneWeekAgo.setDate( oneWeekAgo.getDate() - 7 );
		expect( value ).toBe( oneWeekAgo.toISOString().split( 'T' )[ 0 ] );
	} );

	test( 'changing since date shows grey notice with spinner then resolves', async ( { admin, page, request } ) => {
		const email = `since-change-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );

		await page.route( '**/admin-ajax.php', async ( route ) => {
			if ( ( route.request().postData() ?? '' ).includes( `account_post_id=${ postId }` ) ) {
				await new Promise( ( r ) => setTimeout( r, 600 ) );
			}
			await route.continue();
		} );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const card  = page.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"]` );
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

	test( 'since input hides after a successful check', async ( { admin, page, request } ) => {
		const email = `since-hide-${ Date.now() }@example.com`;
		const postId = await createAccount( request, email );
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		const card  = page.locator( `.bh-mailboxes-account-card[data-account-id="${ postId }"]` );
		await card.locator( '.bh-fetch-since-toggle' ).click( { force: true } );

		const input = card.locator( '.bh-fetch-since-input' );
		await input.fill( '2026-01-01' );
		await input.dispatchEvent( 'change' );
		await waitForCheckResponse( page, postId );

		await expect( input ).not.toBeVisible();
	} );
} );

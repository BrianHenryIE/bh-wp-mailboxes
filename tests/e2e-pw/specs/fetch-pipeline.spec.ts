/**
 * Playwright tests for the fetch pipeline: deduplication and the fixtures "Reset" button.
 *
 * The fixtures connection returns the same five emails on every fetch; the pipeline must save them
 * once (keyed on account + Message-ID) and never duplicate on a re-fetch. The dev-plugin "Reset"
 * button clears the current user's per-user fixture state (read/unread/deleted).
 *
 * Arrange via the dev REST namespace; drive the UI only for the behaviour under test.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext, Page } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';
const POST_TYPE = 'e2e_email';

/** A collision-free email address, even across parallel workers running in the same millisecond. */
function uniqueEmail( prefix: string ): string {
	return `${ prefix }-${ Date.now() }-${ Math.floor( Math.random() * 1_000_000 ) }@example.com`;
}

/** Create a fixtures email account and return its post ID. */
async function createAccount( request: APIRequestContext, emailAddress: string ): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: emailAddress, display_name: emailAddress },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

/** Fetch a single account and return the number of newly-saved emails. */
async function runFetch( request: APIRequestContext, accountId: number ): Promise< number > {
	const res = await request.post( `${ DEV_REST }/fetch`, { data: { account_id: accountId } } );
	expect( res.status() ).toBe( 200 );
	return ( await res.json() ).fetched as number;
}

/** The row DOM ids (e.g. "post-123") currently shown for the emails list, sorted. */
async function rowIds( page: Page ): Promise< string[] > {
	return page
		.locator( '#the-list tr.type-e2e_email' )
		.evaluateAll( ( rows ) => rows.map( ( r ) => r.id ).sort() );
}


test.describe( 'Fetch pipeline — deduplication', () => {
	test( 'first fetch saves five emails; a second fetch saves none and the rows are unchanged', async ( {
		admin,
		page,
		request,
	} ) => {
		const accountId = await createAccount( request, uniqueEmail( 'fetch-dedup' ) );

		// First fetch: all five fixtures are new.
		expect( await runFetch( request, accountId ) ).toBe( 5 );

		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&bh_email_account=${ accountId }` );
		await expect( page.locator( '#the-list tr.type-e2e_email' ) ).toHaveCount( 5 );
		const firstIds = await rowIds( page );

		// Second fetch: the same five fixtures are already saved → nothing new.
		expect( await runFetch( request, accountId ) ).toBe( 0 );

		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&bh_email_account=${ accountId }` );
		await expect( page.locator( '#the-list tr.type-e2e_email' ) ).toHaveCount( 5 );

		// Assert on the actual rows (not just the count): identical post ids, no duplicates.
		expect( await rowIds( page ) ).toEqual( firstIds );
	} );
} );

test.describe( 'Fetch pipeline — Reset button', () => {
	test( 'the Reset button runs and a re-fetch yields five rows without duplicates', async ( {
		admin,
		page,
		request,
	} ) => {
		const accountId = await createAccount( request, uniqueEmail( 'fetch-reset' ) );
		expect( await runFetch( request, accountId ) ).toBe( 5 );

		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&bh_email_account=${ accountId }` );
		// Auto-retry until the five rows are rendered before reading their ids.
		await expect( page.locator( '#the-list tr.type-e2e_email' ) ).toHaveCount( 5 );
		const firstIds = await rowIds( page );

		// Click "Reset" — its handler verifies its own nonce (distinct from the "Check now" nonce), clears
		// the current user's per-user fixture state via reset(), then redirects to the unfiltered list.
		// Assert on that redirect: before the nonce field was renamed the two fields collided, the nonce
		// check failed, and the handler returned WITHOUT redirecting (the reset-fixtures param would remain).
		const resetButton = page.locator( '#reset-fixtures' );
		await resetButton.scrollIntoViewIfNeeded();
		await Promise.all( [
			page.waitForURL(
				( url ) =>
					url.searchParams.get( 'post_type' ) === POST_TYPE &&
					! url.searchParams.has( 'bh_email_account' ) &&
					! url.searchParams.has( 'reset-fixtures' )
			),
			resetButton.click(),
		] );

		// Re-fetch dedupes against the saved posts → still exactly five rows, no duplicates.
		expect( await runFetch( request, accountId ) ).toBe( 0 );
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&bh_email_account=${ accountId }` );
		await expect( page.locator( '#the-list tr.type-e2e_email' ) ).toHaveCount( 5 );
		expect( await rowIds( page ) ).toEqual( firstIds );
	} );
} );

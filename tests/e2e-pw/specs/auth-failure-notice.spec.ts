/**
 * Playwright tests for the auth-failure admin notice (built on wptrt/admin-notices).
 *
 * When a fetch fails to connect, the API records a failed-login time; Admin_Notices then registers a
 * dismissible per-account error notice on the emails list screen, which self-clears on the next success.
 * Each notice's id embeds the failure time, so a dismissal only hides that specific failure — a later,
 * different failure re-notifies.
 *
 * The dev connection is made to fail for a single account via the dev REST `POST /fixtures-fail` flag,
 * so the failure is scoped and does not disturb other accounts fetched in parallel.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

/** A collision-free email address, even across parallel workers running in the same millisecond. */
function uniqueEmail( prefix: string ): string {
	return `${ prefix }-${ Date.now() }-${ Math.floor( Math.random() * 1_000_000 ) }@example.com`;
}

/** The wptrt notice id-prefix selector for an account's auth-failure notices (any failure timestamp). */
function noticeSelector( accountId: number ): string {
	return `[id^="wptrt-notice-bh-wp-mailboxes-auth-failure-${ accountId }-"]`;
}

async function createAccount( request: APIRequestContext, emailAddress: string ): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: emailAddress, display_name: emailAddress },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

async function runFetch( request: APIRequestContext, accountId: number ): Promise< number > {
	const res = await request.post( `${ DEV_REST }/fetch`, { data: { account_id: accountId } } );
	expect( res.status() ).toBe( 200 );
	return ( await res.json() ).fetched as number;
}

/** Toggle the simulated connection failure for a single account. */
async function setFixturesFail( request: APIRequestContext, emailAddress: string, enabled: boolean ) {
	const res = await request.post( `${ DEV_REST }/fixtures-fail`, {
		data: { email_address: emailAddress, enabled },
	} );
	expect( res.ok() ).toBe( true );
}

test.describe( 'Auth failure admin notice', () => {
	// The dev fail flag (`/fixtures-fail`) is a single global option, so these two tests must not set it
	// concurrently — run them in series. (They still run in parallel with other specs, which fetch other
	// accounts the flag does not name.)
	test.describe.configure( { mode: 'serial' } );

	test( 'a failed fetch shows a per-account notice and records the failure; a later success clears it', async ( {
		admin,
		page,
		request,
	} ) => {
		const email = uniqueEmail( 'auth-fail' );
		const accountId = await createAccount( request, email );

		// Make this account's fetch fail, then fetch: the API records last_failed_login_time (0 new emails).
		await setFixturesFail( request, email, true );
		expect( await runFetch( request, accountId ) ).toBe( 0 );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		// The dismissible error notice appears, naming the account.
		const notice = page.locator( noticeSelector( accountId ) );
		await expect( notice ).toBeVisible();
		await expect( notice ).toHaveClass( /is-dismissible/ );
		await expect( notice ).toHaveClass( /notice-error/ );
		await expect( notice ).toContainText( email );

		// The failure was recorded: the card's "Last failure" is no longer "Never".
		const card = page.locator( `.bh-mailboxes-account-card[data-account-id="${ accountId }"]` );
		await expect( card.locator( 'dd[data-field="last-failure"]' ) ).not.toHaveText( 'Never' );

		// Clear the failure and fetch again → a success is recorded → the notice self-clears.
		await setFixturesFail( request, email, false );
		expect( await runFetch( request, accountId ) ).toBe( 5 );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		await expect( page.locator( noticeSelector( accountId ) ) ).toHaveCount( 0 );
	} );

	test( 'each failure gets its own notice id, so a dismissal cannot suppress a later, different failure', async ( {
		admin,
		page,
		request,
	} ) => {
		const email = uniqueEmail( 'auth-id' );
		const accountId = await createAccount( request, email );
		await setFixturesFail( request, email, true );

		// First failure → capture the notice's id (which embeds the failure timestamp).
		expect( await runFetch( request, accountId ) ).toBe( 0 );
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		const firstId = await page.locator( noticeSelector( accountId ) ).getAttribute( 'id' );
		expect( firstId ).toBeTruthy();

		// A later failure (>1s, since the id uses second granularity) stamps a new last_failed_login_time,
		// so its notice id differs. A dismissal keyed on the first id therefore cannot suppress this one —
		// which is exactly what makes the wptrt dismissal safe across separate failures.
		await page.waitForTimeout( 1100 );
		expect( await runFetch( request, accountId ) ).toBe( 0 );
		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		const secondId = await page.locator( noticeSelector( accountId ) ).getAttribute( 'id' );
		expect( secondId ).toBeTruthy();
		expect( secondId ).not.toBe( firstId );

		await setFixturesFail( request, email, false );
	} );
} );

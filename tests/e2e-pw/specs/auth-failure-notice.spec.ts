/**
 * Playwright tests for the auth-failure admin notice.
 *
 * When a fetch fails to connect, the API records a failed-login time; Admin_Notices then renders a
 * dismissible per-account error notice on the emails list screen, which self-clears on the next success.
 *
 * The dev connection is made to fail for a single account via the dev REST `POST /fixtures-fail` flag,
 * so the failure is scoped and does not disturb other accounts fetched in parallel.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

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
	test( 'a failed fetch shows a per-account notice and records the failure; a later success clears it', async ( {
		admin,
		page,
		request,
	} ) => {
		const email = `auth-fail-${ Date.now() }@example.com`;
		const accountId = await createAccount( request, email );

		// Make this account's fetch fail, then fetch: the API records last_failed_login_time (0 new emails).
		await setFixturesFail( request, email, true );
		expect( await runFetch( request, accountId ) ).toBe( 0 );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );

		// The dismissible error notice appears, naming the account.
		const notice = page.locator( `.bh-mailboxes-auth-failure[data-account-id="${ accountId }"]` );
		await expect( notice ).toBeVisible();
		await expect( notice ).toHaveClass( /is-dismissible/ );
		await expect( notice ).toContainText( email );

		// The failure was recorded: the card's "Last failure" is no longer "Never".
		const card = page.locator( `.bh-mailboxes-account-card[data-account-id="${ accountId }"]` );
		await expect( card.locator( 'dd[data-field="last-failure"]' ) ).not.toHaveText( 'Never' );

		// Clear the failure and fetch again → a success is recorded → the notice self-clears.
		await setFixturesFail( request, email, false );
		expect( await runFetch( request, accountId ) ).toBe( 5 );

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		await expect(
			page.locator( `.bh-mailboxes-auth-failure[data-account-id="${ accountId }"]` )
		).toHaveCount( 0 );
	} );
} );

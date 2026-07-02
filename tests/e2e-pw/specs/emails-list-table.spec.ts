/**
 * Playwright tests for the emails list table (WP_List_Table) itself.
 *
 * Covers: fixture emails rendering after a fetch, the search box, the `restrict_manage_posts` account
 * dropdown filter, the post-status filter regression (a specific status must not be clobbered to "any"),
 * and the removal of "Quick Edit" from the row actions.
 *
 * Arrange via the dev REST namespace `bh-wp-mailboxes-dev/v1`; drive the UI only for the behaviour under test.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';
const POST_TYPE = 'fixtures_email';

/** A subject present in exactly one of the five bundled fixture `.eml` files. */
const UNIQUE_FIXTURE_SUBJECT = 'DMARC weekly digest for bhwp.ie';

/** Create a fixtures email account and return its post ID. */
async function createAccount(
	request: APIRequestContext,
	emailAddress: string
): Promise< number > {
	const res = await request.post( `${ DEV_REST }/accounts`, {
		data: { email_address: emailAddress, display_name: emailAddress },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

/**
 * Run the fetch (mirrors the Settings "Run now" button). Pass an account ID to fetch only that account,
 * which keeps parallel tests from racing each other's dedup on shared accounts.
 */
async function runFetch(
	request: APIRequestContext,
	accountId?: number
): Promise< number > {
	const res = await request.post( `${ DEV_REST }/fetch`, {
		data: accountId ? { account_id: accountId } : {},
	} );
	expect( res.status() ).toBe( 200 );
	return ( await res.json() ).fetched as number;
}

/**
 * Create a single fixture email post with the given status, parented to an account so the test can
 * filter the list to just its own emails. Returns the new post ID.
 */
async function createEmail(
	request: APIRequestContext,
	subject: string,
	postStatus: string,
	accountId: number
): Promise< number > {
	const res = await request.post( `${ DEV_REST }/emails`, {
		data: { subject, post_status: postStatus, account_id: accountId },
	} );
	expect( res.status() ).toBe( 201 );
	return ( await res.json() ).post_id as number;
}

test.describe( 'Emails list table — rendering, search and account filter', () => {
	test( 'all five fixture emails render for an account after a fetch', async ( {
		admin,
		page,
		request,
	} ) => {
		const accountId = await createAccount(
			request,
			`list-render-${ Date.now() }@example.com`
		);

		expect( await runFetch( request, accountId ) ).toBe( 5 );

		// Filter to this account so the assertion is independent of emails other tests created.
		await admin.visitAdminPage(
			'edit.php',
			`post_type=${ POST_TYPE }&bh_email_account=${ accountId }`
		);

		// The fixtures connection yields exactly five emails per account.
		await expect( page.locator( '#the-list tr.type-fixtures_email' ) ).toHaveCount( 5 );

		// A recognisable fixture subject is shown as a row title.
		await expect(
			page.locator( '#the-list a.row-title', { hasText: UNIQUE_FIXTURE_SUBJECT } )
		).toHaveCount( 1 );
	} );

	test( 'the search box finds a fixture by its subject', async ( { admin, page, request } ) => {
		const accountId = await createAccount(
			request,
			`list-search-${ Date.now() }@example.com`
		);
		await runFetch( request, accountId );

		// Scope the search to this account so only its single matching email can appear. "weekly" is a
		// single-word term (no space-encoding pitfalls) present in exactly one fixture: the DMARC digest.
		await admin.visitAdminPage(
			'edit.php',
			`post_type=${ POST_TYPE }&bh_email_account=${ accountId }&s=weekly`
		);

		const rows = page.locator( '#the-list tr.type-fixtures_email' );
		await expect( rows ).toHaveCount( 1 );
		await expect( rows ).toContainText( UNIQUE_FIXTURE_SUBJECT );
	} );

	test( 'the account dropdown filters the list to a single account', async ( {
		admin,
		page,
		request,
	} ) => {
		const accountA = await createAccount( request, `list-filter-a-${ Date.now() }@example.com` );
		const accountB = await createAccount( request, `list-filter-b-${ Date.now() }@example.com` );

		await runFetch( request, accountA );
		await runFetch( request, accountB );

		// Capture account B's row IDs from the list filtered to B, so we can later assert none of them
		// leak into account A's view. (The emails CPT is not exposed on the wp/v2 REST API.)
		await admin.visitAdminPage(
			'edit.php',
			`post_type=${ POST_TYPE }&bh_email_account=${ accountB }`
		);
		const bRowIds = await page
			.locator( '#the-list tr.type-fixtures_email' )
			.evaluateAll( ( rows ) => rows.map( ( r ) => r.id ) );
		expect( bRowIds ).toHaveLength( 5 );

		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }` );

		// With more than one account, the restrict_manage_posts dropdown is rendered.
		const dropdown = page.locator( 'select#bh_email_account' );
		await expect( dropdown ).toBeVisible();
		await expect( dropdown.locator( `option[value="${ accountA }"]` ) ).toHaveCount( 1 );

		// Selecting account A and filtering shows only account A's five emails.
		await dropdown.selectOption( String( accountA ) );
		await page.locator( '#post-query-submit' ).click();

		await expect( page ).toHaveURL( new RegExp( `bh_email_account=${ accountA }` ) );
		await expect( page.locator( '#the-list tr.type-fixtures_email' ) ).toHaveCount( 5 );

		// None of account B's rows appear under account A's filter.
		for ( const id of bRowIds ) {
			await expect( page.locator( `#${ id }` ) ).toHaveCount( 0 );
		}
	} );
} );

test.describe( 'Emails list table — post-status filter (regression)', () => {
	test( 'filtering by a specific status shows only emails of that status', async ( {
		admin,
		page,
		request,
	} ) => {
		const accountId = await createAccount( request, `list-status-${ Date.now() }@example.com` );
		const savedId = await createEmail( request, 'Saved status email', 'bh_email_saved', accountId );
		const newId = await createEmail( request, 'New status email', 'bh_email_new', accountId );

		// Request only the "Saved" status via the status-list link's query arg (scoped to this account).
		await admin.visitAdminPage(
			'edit.php',
			`post_type=${ POST_TYPE }&bh_email_account=${ accountId }&post_status=bh_email_saved`
		);

		// The saved email is listed; the new one is filtered out. Before the fix, show_all_post_statuses()
		// unconditionally set post_status => 'any', so both rows appeared regardless of the selection.
		await expect( page.locator( `#post-${ savedId }` ) ).toHaveCount( 1 );
		await expect( page.locator( `#post-${ newId }` ) ).toHaveCount( 0 );
	} );

	test( 'the default view still shows all custom statuses', async ( { admin, page, request } ) => {
		const accountId = await createAccount( request, `list-default-${ Date.now() }@example.com` );
		const savedId = await createEmail( request, 'Default view saved email', 'bh_email_saved', accountId );
		const newId = await createEmail( request, 'Default view new email', 'bh_email_new', accountId );

		await admin.visitAdminPage(
			'edit.php',
			`post_type=${ POST_TYPE }&bh_email_account=${ accountId }`
		);

		// No explicit status → both custom statuses appear (post_status defaults to "any").
		await expect( page.locator( `#post-${ savedId }` ) ).toHaveCount( 1 );
		await expect( page.locator( `#post-${ newId }` ) ).toHaveCount( 1 );
	} );
} );

test.describe( 'Emails list table — row actions', () => {
	test( '"Quick Edit" is absent from the row actions', async ( { admin, page, request } ) => {
		const accountId = await createAccount( request, `list-quickedit-${ Date.now() }@example.com` );
		const postId = await createEmail( request, 'Quick edit check email', 'bh_email_new', accountId );

		await admin.visitAdminPage(
			'edit.php',
			`post_type=${ POST_TYPE }&bh_email_account=${ accountId }`
		);

		const row = page.locator( `#post-${ postId }` );
		await expect( row ).toHaveCount( 1 );

		// Quick Edit renders as an <a>/<button> with class "editinline"; it must not be offered.
		await expect( row.locator( '.row-actions .editinline' ) ).toHaveCount( 0 );

		// Sanity: the row still has its other actions (e.g. the relabelled "Trash locally").
		await expect( row.locator( '.row-actions' ) ).toContainText( 'Trash locally' );
	} );
} );

/**
 * Playwright tests for email threads on the single email view.
 *
 * Arrange via the dev plugin's fixture REST route (which accepts In-Reply-To / References headers so a
 * conversation can be built), assert via the "Thread" metabox on the WP admin edit screen.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v2';

type FixtureEmail = { post_id: number; message_id: string };

test.describe( 'Email threads', () => {
	/**
	 * Create a fixture email; `in_reply_to` / `references` are raw header values (angle-bracketed ids).
	 */
	async function createEmail(
		request: Parameters< typeof test >[ 1 ][ 'request' ],
		data: Record< string, unknown > = {}
	): Promise< FixtureEmail > {
		const subject = data.subject ?? `E2E thread ${ Date.now() }`;
		const res = await request.post( `${ DEV_REST }/emails`, {
			data: { subject, ...data },
		} );
		expect( res.status() ).toBe( 201 );
		const body = await res.json();
		return { post_id: body.post_id as number, message_id: body.message_id as string };
	}

	test( 'a lone email has no Thread metabox', async ( { admin, page, request } ) => {
		const email = await createEmail( request, { subject: `E2E thread solo ${ Date.now() }` } );
		await admin.visitAdminPage( 'post.php', `post=${ email.post_id }&action=edit` );

		await expect( page.locator( '#bh-email-local-status' ) ).toBeVisible();
		await expect( page.locator( '#bh-email-thread' ) ).toHaveCount( 0 );
	} );

	test( 'a reply and its root link to each other in the Thread metabox', async ( { admin, page, request } ) => {
		const unique = Date.now();
		const root = await createEmail( request, {
			subject: `E2E thread root ${ unique }`,
			date_header: 'Mon, 01 Sep 2025 10:00:00 +0000',
		} );
		const reply = await createEmail( request, {
			subject: `Re: E2E thread root ${ unique }`,
			date_header: 'Mon, 01 Sep 2025 11:00:00 +0000',
			in_reply_to: root.message_id,
			references: root.message_id,
		} );

		// On the reply: the root is listed first and linked; the reply itself is marked current and unlinked.
		await admin.visitAdminPage( 'post.php', `post=${ reply.post_id }&action=edit` );
		const metabox = page.locator( '#bh-email-thread' );
		await expect( metabox ).toBeVisible();
		const items = metabox.locator( '.bh-email-thread-list__item' );
		await expect( items ).toHaveCount( 2 );
		await expect( items.nth( 0 ) ).toHaveAttribute( 'data-post-id', String( root.post_id ) );
		await expect( items.nth( 1 ) ).toHaveAttribute( 'data-post-id', String( reply.post_id ) );
		await expect( items.nth( 1 ) ).toHaveClass( /bh-email-thread-list__item--current/ );
		await expect( items.nth( 1 ).locator( 'a' ) ).toHaveCount( 0 );

		// Following the root's link lands on the root's edit screen, where the reply is listed and linked back.
		await items.nth( 0 ).getByRole( 'link', { name: `E2E thread root ${ unique }` } ).click();
		await expect( page ).toHaveURL( new RegExp( `post=${ root.post_id }&action=edit` ) );
		const rootItems = page.locator( '#bh-email-thread .bh-email-thread-list__item' );
		await expect( rootItems ).toHaveCount( 2 );
		await expect( rootItems.nth( 0 ) ).toHaveClass( /bh-email-thread-list__item--current/ );
		await expect(
			rootItems.nth( 1 ).getByRole( 'link', { name: `Re: E2E thread root ${ unique }` } )
		).toHaveAttribute( 'href', new RegExp( `post=${ reply.post_id }&action=edit` ) );
	} );

	test( 'a reply arriving before its root still shares the thread', async ( { admin, page, request } ) => {
		const unique = Date.now();
		const rootMessageId = `<e2e-late-root-${ unique }@bh-wp-mailboxes.test>`;

		// The reply references a Message-ID that is not stored yet.
		const reply = await createEmail( request, {
			subject: `Re: E2E late root ${ unique }`,
			in_reply_to: rootMessageId,
			references: rootMessageId,
		} );

		// A second reply to the same unstored root joins the first reply's thread via the shared reference.
		const sibling = await createEmail( request, {
			subject: `Re: E2E late root ${ unique } (2)`,
			in_reply_to: rootMessageId,
			references: rootMessageId,
		} );

		await admin.visitAdminPage( 'post.php', `post=${ sibling.post_id }&action=edit` );
		const items = page.locator( '#bh-email-thread .bh-email-thread-list__item' );
		await expect( items ).toHaveCount( 2 );
		await expect( page.locator( `#bh-email-thread [data-post-id="${ reply.post_id }"]` ) ).toBeVisible();
	} );
} );

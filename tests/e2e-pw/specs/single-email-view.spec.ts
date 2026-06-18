/**
 * Playwright tests for the single email edit/view screen.
 *
 * Arrange via REST, assert via the WP admin edit screen.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

test.describe( 'Single email view', () => {
	/**
	 * Helper: create a fixture email and return its post_id.
	 */
	async function createEmail(
		request: Parameters< typeof test >[ 1 ][ 'request' ],
		data: Record< string, unknown > = {}
	): Promise< number > {
		const subject = data.subject ?? `E2E single-view ${ Date.now() }`;
		const res = await request.post( `${ DEV_REST }/emails`, {
			data: { subject, ...data },
		} );
		expect( res.status() ).toBe( 201 );
		const body = await res.json();
		return body.post_id as number;
	}

	test( 'page heading says "Email", not "Edit Email"', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const heading = page.locator( 'h1.wp-heading-inline' );
		await expect( heading ).toBeVisible();
		await expect( heading ).toContainText( 'Email' );
		await expect( heading ).not.toContainText( 'Edit Email' );
	} );

	test( '"Add New Email" button is not visible on the single-email screen', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '.page-title-action' ) ).not.toBeVisible();
	} );

	test( 'default submitdiv is absent; custom Email Status metabox is present', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#submitdiv' ) ).not.toBeAttached();
		await expect( page.locator( '#bh-email-local-status' ) ).toBeVisible();
	} );

	test( 'Email Headers postbox is present', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-headers' ) ).toBeVisible();
	} );

	test( 'Email Headers are rendered as a responsive grid (not a table)', async ( { admin, page, request } ) => {
		// A body gives the stored MIME Content-Type / MIME-Version headers to display.
		const postId = await createEmail( request, { body_plain: 'Header grid test body.' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// toBeAttached (not toBeVisible): the postbox may be collapsed by a parallel test (shared user meta).
		await expect( page.locator( '#bh-email-headers .bh-email-headers-grid' ) ).toBeAttached();
		await expect( page.locator( '#bh-email-headers table' ) ).toHaveCount( 0 );
	} );

	test( 'HTML content metabox shows an iframe when email has HTML body', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { body_html: '<p>Hello HTML</p>' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-content-html' ) ).toBeVisible();
		await expect( page.locator( '#bh-email-content-html iframe.bh-email-html-body' ) ).toBeAttached();
	} );

	test( 'plain-text content metabox shows an iframe when email has plain text body', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { body_plain: 'Hello plain text.' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-content-plain' ) ).toBeVisible();
		await expect( page.locator( '#bh-email-content-plain iframe.bh-email-plain-body' ) ).toBeAttached();
	} );

	test( 'title input is readonly (no editor toolbar, readonly attribute set by JS)', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { subject: 'Immutable Subject' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// The TinyMCE editor toolbar must be absent (editor support removed from CPT).
		await expect( page.locator( '#wp-content-editor-tools' ) ).not.toBeAttached();

		// JS adds readonly + tabindex=-1 so keyboard input and programmatic focus are
		// both blocked. toBeEditable() fails when the element is readonly or disabled.
		await expect( page.locator( '#title' ) ).not.toBeEditable();
	} );

	test( 'title cannot be changed even when form is submitted with a modified value', async ( { admin, page, request } ) => {
		const originalSubject = 'Original Immutable Subject';
		const postId = await createEmail( request, { subject: originalSubject } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// Bypass CSS by setting the input value directly via JS, simulating what a
		// determined user or malfunctioning script could do.
		await page.locator( '#title' ).evaluate(
			( el, val ) => { ( el as HTMLInputElement ).value = val; },
			'Attempted Overwrite'
		);

		// Submit the form via our Email Status metabox Save button.
		await page.locator( '#save' ).click();
		await page.waitForURL( new RegExp( `post=${ postId }` ) );

		// The wp_insert_post_data filter must have restored the original title.
		await expect( page.locator( '#title' ) ).toHaveValue( originalSubject );
	} );

	test( 'status is a radio select including the custom email statuses', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const options = page.locator( '.bh-email-status__options' );
		await expect( options ).toBeVisible();
		await expect( options.locator( 'input[type="radio"][name="post_status"][value="bh_email_new"]' ) ).toBeAttached();
		await expect( options.locator( 'input[type="radio"][name="post_status"][value="bh_email_processed"]' ) ).toBeAttached();
		await expect( options.locator( 'input[type="radio"][name="post_status"][value="bh_email_saved"]' ) ).toBeAttached();
	} );

	// -------------------------------------------------------------------------
	// Requirement 3: Email Status metabox title
	// -------------------------------------------------------------------------

	test( 'Local status metabox title says "Local status"', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const metaboxTitle = page.locator( '#bh-email-local-status .postbox-header h2' );
		await expect( metaboxTitle ).toBeVisible();
		await expect( metaboxTitle ).toContainText( 'Local status' );
	} );

	test( 'Remote status metabox title says "Remote status"', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const metaboxTitle = page.locator( '#bh-email-remote-status .postbox-header h2' );
		await expect( metaboxTitle ).toBeVisible();
		await expect( metaboxTitle ).toContainText( 'Remote status' );
	} );

	// -------------------------------------------------------------------------
	// Requirement 5: Visibility selector absent
	// -------------------------------------------------------------------------

	test( 'no visibility selector is present on the single email screen', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// The #visibility row lives inside submitdiv; since submitdiv is removed it should not appear.
		await expect( page.locator( '#visibility' ) ).not.toBeAttached();
	} );

	// -------------------------------------------------------------------------
	// Requirement 6: Date labels in Email Status metabox
	// -------------------------------------------------------------------------

	test( 'status metabox shows "Downloaded at:" label, not "Published on"', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const statusBox = page.locator( '#bh-email-local-status' );
		await expect( statusBox ).toContainText( 'Downloaded at:' );
		await expect( statusBox ).not.toContainText( 'Published on' );
	} );

	test( 'remote status metabox shows "Sent:" from the email Date header when present', async ( { admin, page, request } ) => {
		const dateHeader = 'Mon, 01 Jan 2024 10:30:00 +0000';
		const postId = await createEmail( request, { date_header: dateHeader } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const statusBox = page.locator( '#bh-email-remote-status' );
		await expect( statusBox ).toContainText( 'Sent:' );
	} );

	test( 'remote status metabox always shows "Sent:" even when email has no Date header', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-remote-status' ) ).toContainText( 'Sent:' );
	} );

	test( 'local status metabox always shows "Updated at:"', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-local-status' ) ).toContainText( 'Updated at:' );
	} );

	// -------------------------------------------------------------------------
	// Requirement 9: No "Add Media" button
	// -------------------------------------------------------------------------

	test( '"Add Media" button is not visible on the single email screen', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#media-buttons' ) ).not.toBeVisible();
	} );

	// -------------------------------------------------------------------------
	// Requirement 10: Remote status badges
	// -------------------------------------------------------------------------

	// Badges are gated on provider.can_read_status() && can_delete_on_server(); fixture emails have
	// no linked account/provider, so badges never appear. Skip until provider setup is added.
	test.skip( '"Read on server" badge shown when email is_read meta is true', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { is_read: true } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '.bh-email-badge--read' ) ).toBeVisible();
		await expect( page.locator( '.bh-email-badge--unread' ) ).not.toBeAttached();
	} );

	test.skip( '"Unread on server" badge shown when email is_read meta is false', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { is_read: false } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '.bh-email-badge--unread' ) ).toBeVisible();
		await expect( page.locator( '.bh-email-badge--read' ) ).not.toBeAttached();
	} );

	test( 'no remote status badge shown when is_read meta is absent', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '.bh-email-badge--read' ) ).not.toBeAttached();
		await expect( page.locator( '.bh-email-badge--unread' ) ).not.toBeAttached();
	} );

	// -------------------------------------------------------------------------
	// Requirement 12 + 13: postbox class on header and content metaboxes
	// -------------------------------------------------------------------------

	test( 'Email Headers metabox has the WordPress postbox class', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-headers' ) ).toHaveClass( /postbox/ );
	} );

	test( 'HTML content metabox has the WordPress postbox class', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { body_html: '<p>HTML body</p>' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-content-html' ) ).toHaveClass( /postbox/ );
	} );

	test( 'plain-text content metabox has the WordPress postbox class', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { body_plain: 'Plain body.' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect( page.locator( '#bh-email-content-plain' ) ).toHaveClass( /postbox/ );
	} );

	// -------------------------------------------------------------------------
	// iframe resize on postbox toggle
	// -------------------------------------------------------------------------

	test( 'HTML iframe height is recalculated after collapsing and re-expanding the postbox', async ( { admin, page, request } ) => {
		test.setTimeout( 60_000 );

		const postId = await createEmail( request, { body_html: '<p>Hello HTML</p>' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const iframe = page.locator( '#bh-email-content-html iframe.bh-email-html-body' );
		await expect( iframe ).toBeAttached();

		// Wait for initial load + resize to fire before collapsing.
		await page.waitForFunction( () => {
			const el = document.querySelector( '#bh-email-content-html iframe.bh-email-html-body' ) as HTMLIFrameElement | null;
			return el !== null && el.style.height !== '';
		} );

		// Collapse: click and wait for the 'closed' class to be applied.
		await page.locator( '#bh-email-content-html .toggle-indicator' ).click();
		await expect( page.locator( '#bh-email-content-html' ) ).toHaveClass( /closed/, { timeout: 15_000 } );

		// Expand: click and wait for 'closed' to be removed.
		await page.locator( '#bh-email-content-html .toggle-indicator' ).click();
		await expect( page.locator( '#bh-email-content-html' ) ).not.toHaveClass( /closed/, { timeout: 15_000 } );

		// Poll until the setTimeout(0) resize callback sets a positive height.
		await expect.poll(
			() => iframe.evaluate( ( el ) => parseInt( ( el as HTMLIFrameElement ).style.height ?? '0', 10 ) ),
			{ timeout: 10_000 }
		).toBeGreaterThan( 0 );
	} );

	test( 'plain-text iframe height is recalculated after collapsing and re-expanding the postbox', async ( { admin, page, request } ) => {
		test.setTimeout( 60_000 );

		const postId = await createEmail( request, { body_plain: 'Hello plain text.' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const iframe = page.locator( '#bh-email-content-plain iframe.bh-email-plain-body' );
		await expect( iframe ).toBeAttached();

		// Wait for initial load + resize to fire before collapsing.
		await page.waitForFunction( () => {
			const el = document.querySelector( '#bh-email-content-plain iframe.bh-email-plain-body' ) as HTMLIFrameElement | null;
			return el !== null && el.style.height !== '';
		} );

		// Collapse: click and wait for the 'closed' class to be applied.
		await page.locator( '#bh-email-content-plain .toggle-indicator' ).click();
		await expect( page.locator( '#bh-email-content-plain' ) ).toHaveClass( /closed/, { timeout: 15_000 } );

		// Expand: click and wait for 'closed' to be removed.
		await page.locator( '#bh-email-content-plain .toggle-indicator' ).click();
		await expect( page.locator( '#bh-email-content-plain' ) ).not.toHaveClass( /closed/, { timeout: 15_000 } );

		// Poll until the setTimeout(0) resize callback sets a positive height.
		await expect.poll(
			() => iframe.evaluate( ( el ) => parseInt( ( el as HTMLIFrameElement ).style.height ?? '0', 10 ) ),
			{ timeout: 10_000 }
		).toBeGreaterThan( 0 );
	} );

	// -------------------------------------------------------------------------
	// Requirement 14: Attachments metabox in side column
	// -------------------------------------------------------------------------

	// Attachments metabox registration is commented out in class-single-email-view.php pending
	// a decision on how attachment file paths are stored. Skip until re-enabled.
	test.skip( 'Attachments metabox appears inside the side-sortables column', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { has_attachment: true } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// #side-sortables is the WordPress container for all side-column metaboxes.
		await expect(
			page.locator( '#side-sortables #bh-email-attachments' )
		).toBeVisible();
	} );

	// -------------------------------------------------------------------------
	// Local status metabox: trash link, in-mailbox link, icons, selectable title
	// -------------------------------------------------------------------------

	test( 'local status metabox has a red "Move to Trash" link', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// toBeAttached (not toBeVisible): postbox collapse state is shared per-user across parallel tests.
		const trash = page.locator( '#bh-email-local-status .submitdelete' );
		await expect( trash ).toBeAttached();
		await expect( trash ).toContainText( 'Move to Trash' );
	} );

	test( 'local status metabox has an "In mailbox:" link back to the list', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const metabox = page.locator( '#bh-email-local-status' );
		await expect( metabox ).toContainText( 'In mailbox:' );

		// The mailbox name links back to this CPT's list.
		const link = metabox.locator( 'a[href*="edit.php?post_type=fixtures_email"]' );
		await expect( link ).toBeAttached();
	} );

	test( 'status and datetime fields render dashicon markers', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await expect(
			page.locator( '#bh-email-local-status .bh-email-field__icon--status' )
		).toHaveCount( 1 );
		await expect(
			page.locator( '#bh-email-local-status .bh-email-field__icon--datetime' ).first()
		).toBeAttached();
	} );

	test( 'the immutable title is read-only but still selectable', async ( { admin, page, request } ) => {
		const postId = await createEmail( request );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		const title = page.locator( '#title' );
		await expect( title ).toHaveAttribute( 'readonly', '' );

		// pointer-events:none would prevent text selection; it must not be set.
		const pointerEvents = await title.evaluate( ( el ) => window.getComputedStyle( el ).pointerEvents );
		expect( pointerEvents ).not.toBe( 'none' );
	} );

	test( 'changing the status records a log entry with the old and new status', async ( { admin, page, request } ) => {
		const postId = await createEmail( request, { post_status: 'bh_email_new' } );
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		await page.locator( 'input[name="post_status"][value="bh_email_processed"]' ).check();

		// Save goes through the API (AJAX) rather than the native post save.
		const updateResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'admin-ajax.php' ) &&
				( res.request().postData() ?? '' ).includes( 'bh_wp_mailboxes_update_status' )
		);
		await page.locator( '#bh-email-status-box #save' ).click();
		await updateResponse;

		// The JS reloads on success; the log metabox then shows the status change.
		const log = page.locator( '#bh-email-log-notes' );
		await expect( log ).toContainText( 'Status changed', { timeout: 10_000 } );
		await expect( log ).toContainText( 'bh_email_processed' );
	} );

	test( 'remote read status: changing the radio and clicking Update persists across reload', async ( { admin, page, request } ) => {
		// An account using the fixtures provider (which supports marking read) is needed so the
		// single-email view shows the read-status radios. Fetch emails for it so they are linked to it.
		const accountRes = await request.post( `${ DEV_REST }/accounts`, {
			data: { email_address: `remote-update-${ Date.now() }@example.com` },
		} );
		expect( accountRes.status() ).toBe( 201 );
		const accountId = ( await accountRes.json() ).post_id as number;

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		const checkResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'admin-ajax.php' ) &&
				( res.request().postData() ?? '' ).includes( `account_post_id=${ accountId }` )
		);
		await page.locator( `.bh-check-account[data-account-id="${ accountId }"]` ).click( { force: true } );
		const checkBody = await ( await checkResponse ).json();
		const emailId = checkBody.data.new_email_ids[ 0 ] as number;
		expect( emailId ).toBeTruthy();

		// Open the fetched email and wait for the on-load remote-status refresh to settle.
		const openEmail = async () => {
			await admin.visitAdminPage( 'post.php', `post=${ emailId }&action=edit` );
			const options = page.locator( '#bh-email-read-status-options' );
			await expect( options ).toBeAttached();
			await expect( options ).not.toHaveClass( /is-loading/ );
		};

		// Update the read status through both states and confirm each sticks after a reload. This is
		// independent of the (shared) initial state, proving the Update button persists the change.
		const updateAndReload = async ( value: 'read' | 'unread', actionFragment: string ) => {
			await openEmail();
			await page.locator( `input[name="bh_email_remote_read"][value="${ value }"]` ).check( { force: true } );
			const markResponse = page.waitForResponse(
				( res ) =>
					res.url().includes( 'admin-ajax.php' ) &&
					( res.request().postData() ?? '' ).includes( actionFragment )
			);
			await page.locator( '#bh-email-remote-save' ).click( { force: true } );
			await markResponse;

			await openEmail();
			await expect( page.locator( `input[name="bh_email_remote_read"][value="${ value }"]` ) ).toBeChecked();
		};

		// "mark_unread" contains "mark_", but only the read action contains "mark_read".
		await updateAndReload( 'read', 'mark_read' );
		await updateAndReload( 'unread', 'mark_unread' );
	} );

	test( 'a remote action updates the Email Log immediately, without a page reload', async ( { admin, page, request } ) => {
		const accountRes = await request.post( `${ DEV_REST }/accounts`, {
			data: { email_address: `log-update-${ Date.now() }@example.com` },
		} );
		expect( accountRes.status() ).toBe( 201 );
		const accountId = ( await accountRes.json() ).post_id as number;

		await admin.visitAdminPage( 'edit.php', 'post_type=fixtures_email' );
		const checkResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'admin-ajax.php' ) &&
				( res.request().postData() ?? '' ).includes( `account_post_id=${ accountId }` )
		);
		await page.locator( `.bh-check-account[data-account-id="${ accountId }"]` ).click( { force: true } );
		const emailId = ( await ( await checkResponse ).json() ).data.new_email_ids[ 0 ] as number;
		expect( emailId ).toBeTruthy();

		await admin.visitAdminPage( 'post.php', `post=${ emailId }&action=edit` );
		await expect( page.locator( '#bh-email-read-status-options' ) ).not.toHaveClass( /is-loading/ );

		const log = page.locator( '#bh-email-log-notes' );
		await expect( log ).not.toContainText( 'Marked as read on server' );

		// Mark read + Update. The log must reflect the new entry without navigating/reloading.
		const urlBefore = page.url();
		await page.locator( 'input[name="bh_email_remote_read"][value="read"]' ).check( { force: true } );
		await page.locator( '#bh-email-remote-save' ).click( { force: true } );

		await expect( log ).toContainText( 'Marked as read on server', { timeout: 10_000 } );
		expect( page.url() ).toBe( urlBefore );
	} );
} );

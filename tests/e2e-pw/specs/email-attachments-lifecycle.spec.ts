/**
 * An email's attachments follow the email through its lifecycle.
 *
 * Delivers a multipart email with an attachment through the REST ingress (the same path the Cloudflare
 * worker uses), then proves: the attachment is saved as a private-uploads post with its file on disk and
 * listed on the single email view; trashing the email (wp-admin's "Trash locally" row action) trashes
 * the attachment post and keeps the file; restoring the email restores the attachment to its previous
 * status; permanently deleting the email deletes the attachment post and its file.
 *
 * The delivery authenticates with the signed-in administrator's cookies and a REST nonce rather than an
 * application password: creating application passwords races across parallel workers (core's create
 * path is a read-modify-write on user meta), and the ingress spec already covers Basic auth.
 *
 * The attachment state is read through the development plugin's
 * `GET /bh-wp-mailboxes-dev/v2/emails/{id}/attachments` route. The core row-action links are followed
 * with `page.goto()` rather than clicked: after an admin redirect headless Chromium stops producing
 * frames, so a click's actionability wait can hang.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';
import path from 'path';
import fs from 'fs';

const BASE_URL = process.env.BASEURL || process.env.WP_BASE_URL || 'http://localhost:8888';
const FIXTURES_DIR = path.resolve( __dirname, '../fixtures' );
const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v2';
const INGRESS_PATH = `${ DEV_REST }/e2e-email/new`;
const POST_TYPE = 'e2e_email';

interface AttachmentState {
	post_id: number;
	post_status: string | false;
	file: string | null;
	file_exists: boolean;
}

interface EmailState {
	post_status: string;
	attachments: AttachmentState[];
}

/** Read the attachment fixture, uniquifying its Message-ID and Subject so the dedupe never matches an earlier run. */
function uniquifiedFixture(): { raw: string; subject: string } {
	const raw = fs.readFileSync( path.join( FIXTURES_DIR, 'multipart-with-attachment.eml' ), 'utf8' );
	const unique = `${ Date.now() }-${ Math.floor( Math.random() * 1_000_000 ) }`;
	const subject = `E2E attachments ${ unique }`;
	return {
		raw: raw
			.replace( /^Subject: .*$/m, `Subject: ${ subject }` )
			.replace( /^Message-ID: .*$/m, `Message-ID: <e2e-attachments-${ unique }@bh-wp-mailboxes.test>` ),
		subject,
	};
}

/**
 * Deliver raw MIME to the e2e mailbox's ingress endpoint with the worker's headers, authenticated as the
 * signed-in administrator (cookies + the REST nonce core hands out from admin-ajax). Returns the new post id.
 */
async function deliver( request: APIRequestContext, rawMime: string ): Promise< number > {
	const nonce = await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
	const response = await request.post( INGRESS_PATH, {
		headers: {
			'Content-Type': 'message/rfc822',
			'X-WP-Nonce': nonce,
			'X-Envelope-From': 'sender@example.com',
			'X-Envelope-To': 'mailbox@example.org',
			'X-Message-Raw-Size': String( Buffer.byteLength( rawMime ) ),
		},
		data: rawMime,
	} );
	expect( response.status() ).toBe( 201 );
	return ( await response.json() ).post_id as number;
}

/** The email's status and attachment state, or null once the email post is gone. */
async function emailState( postId: number ): Promise< EmailState | null > {
	const response = await fetch( new URL( `${ DEV_REST }/emails/${ postId }/attachments`, BASE_URL ).href );
	if ( response.status === 404 ) {
		return null;
	}
	expect( response.status ).toBe( 200 );
	return ( await response.json() ) as EmailState;
}

/** Whether an attachment post, and the file at the given server path, still exist. */
async function attachmentExists( attachmentId: number, file: string ): Promise< { post_exists: boolean; file_exists: boolean | null } > {
	const url = new URL( `${ DEV_REST }/attachments/${ attachmentId }`, BASE_URL );
	url.searchParams.set( 'file', file );
	const response = await fetch( url.href );
	expect( response.status ).toBe( 200 );
	return await response.json();
}

test.describe( 'Email attachments — save, trash, restore, delete', () => {
	test( 'a delivered attachment is saved as a private-uploads post with its file, and follows the email through trash, restore and delete', async ( {
		admin,
		page,
		request,
	} ) => {
		// Eight admin page loads in one test: allow three times the default timeout under a loaded parallel run.
		test.slow();

		const fixture = uniquifiedFixture();
		const postId = await deliver( request, fixture.raw );

		// Saved: one attachment post (status `inherit`, as WordPress gives attachments) with a file on disk.
		const saved = await emailState( postId );
		expect( saved ).not.toBeNull();
		expect( saved!.post_status ).toBe( 'bh_email_new' );
		expect( saved!.attachments ).toHaveLength( 1 );
		const attachment = saved!.attachments[ 0 ];
		expect( attachment.post_status ).toBe( 'inherit' );
		expect( attachment.file ).toMatch( /\/minutes(-\d+)?\.csv$/ ); // WordPress uniquifies repeated filenames.
		expect( attachment.file_exists ).toBe( true );

		// Listed on the single email view.
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await expect( page.locator( '#bh-email-attachments .bh-email-attachments-list' ) ).toContainText( /minutes(-\d+)?\.csv/ );

		// Trash locally, from the list's row action (a core link).
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&s=${ encodeURIComponent( fixture.subject ) }` );
		const trashHref = await page.locator( `#post-${ postId } .row-actions .trash a` ).getAttribute( 'href' );
		expect( trashHref ).toBeTruthy();
		await page.goto( trashHref! );

		const trashed = await emailState( postId );
		expect( trashed!.post_status ).toBe( 'trash' );
		expect( trashed!.attachments ).toHaveLength( 1 );
		expect( trashed!.attachments[ 0 ].post_status ).toBe( 'trash' );
		expect( trashed!.attachments[ 0 ].file_exists ).toBe( true );

		// Restore, from the Trash view's row action.
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&post_status=trash&s=${ encodeURIComponent( fixture.subject ) }` );
		const restoreHref = await page.locator( `#post-${ postId } .row-actions .untrash a` ).getAttribute( 'href' );
		expect( restoreHref ).toBeTruthy();
		await page.goto( restoreHref! );

		const restored = await emailState( postId );
		expect( restored!.post_status ).toBe( 'bh_email_new' );
		expect( restored!.attachments[ 0 ].post_status ).toBe( 'inherit' );
		expect( restored!.attachments[ 0 ].file_exists ).toBe( true );

		// The attachment is listed again after the restore.
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await expect( page.locator( '#bh-email-attachments .bh-email-attachments-list' ) ).toContainText( /minutes(-\d+)?\.csv/ );

		// Trash again, then delete permanently from the Trash view.
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&s=${ encodeURIComponent( fixture.subject ) }` );
		await page.goto( ( await page.locator( `#post-${ postId } .row-actions .trash a` ).getAttribute( 'href' ) )! );
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&post_status=trash&s=${ encodeURIComponent( fixture.subject ) }` );
		const deleteHref = await page.locator( `#post-${ postId } .row-actions .delete a` ).getAttribute( 'href' );
		expect( deleteHref ).toBeTruthy();
		await page.goto( deleteHref! );

		// Gone: the email post, the attachment post and the file (which lives on the server, so it is
		// checked there by the path reported while the attachment still existed).
		expect( await emailState( postId ) ).toBeNull();
		const gone = await attachmentExists( attachment.post_id, attachment.file! );
		expect( gone.post_exists ).toBe( false );
		expect( gone.file_exists ).toBe( false );
	} );
} );

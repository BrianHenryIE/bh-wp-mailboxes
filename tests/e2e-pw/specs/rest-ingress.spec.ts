/**
 * Playwright tests for the REST email-ingress endpoint against the live wp-env site.
 *
 * Proves the Cloudflare worker ⇄ plugin contract end-to-end the way the worker actually uses it:
 * discover the endpoint from the unauthenticated REST index (`email_ingress_endpoints`), then POST
 * raw MIME (`message/rfc822`) authenticated with a WordPress application password over Basic auth.
 * The worker's own `.eml` fixtures are the payloads.
 *
 * The ingress requests use plain `fetch()` — the same API the worker delivers with — rather than
 * Playwright's request context. (Playwright's APIRequestContext drops/mishandles the Basic
 * Authorization header under the test runner; `fetch` sends it reliably, and it is closer to the
 * worker's real behaviour anyway.)
 *
 * The suite's global teardown asserts debug.log is empty, so any PHP notice raised by the
 * endpoint (e.g. a missing permission_callback) fails the run.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import path from 'path';
import fs from 'fs';

// Run this file's tests in one worker, sequentially: each parallel worker would run beforeAll and
// create an application password concurrently, and WordPress core's create path is a
// read-modify-write on the `_application_passwords` user-meta array — concurrent creates clobber
// each other and previously-issued passwords stop authenticating.
test.describe.configure( { mode: 'serial' } );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const BASE_URL = process.env.BASEURL || process.env.WP_BASE_URL || 'http://localhost:8888';

const WORKER_FIXTURES_DIR = path.resolve( __dirname, '../../../cloudflare-worker/tests/fixtures' );

const DEV_INGRESS_NAMESPACE = 'bh-wp-mailboxes-dev/v2';
const INGRESS_PATH = `/wp-json/${ DEV_INGRESS_NAMESPACE }/e2e-email/new`;
const POST_TYPE = 'e2e_email';

/** The application password created for this run (plaintext is only available at creation). */
let applicationPassword: string;
let applicationPasswordUuid: string;

function basicAuthHeader( password: string ): string {
	return `Basic ${ Buffer.from( `${ ADMIN_USER }:${ password }` ).toString( 'base64' ) }`;
}

/**
 * Read a worker fixture and uniquify its Message-ID and Subject so repeated test runs (and the
 * repository's message-id dedupe) don't collide with earlier runs' saved posts.
 */
function uniquifiedFixture( filename: string ): { raw: string; subject: string; messageId: string } {
	const raw = fs.readFileSync( path.join( WORKER_FIXTURES_DIR, filename ), 'utf8' );
	const unique = `${ Date.now() }-${ Math.floor( Math.random() * 1_000_000 ) }`;
	const subject = `E2E ingress ${ unique }`;
	const messageId = `<e2e-ingress-${ unique }@bh-wp-mailboxes.test>`;
	const uniquified = raw
		.replace( /^Subject: .*$/m, `Subject: ${ subject }` )
		.replace( /^Message-ID: .*$/m, `Message-ID: ${ messageId }` );
	return { raw: uniquified, subject, messageId };
}

/** POST a raw MIME message to the ingress endpoint with worker-style headers, as the worker does. */
async function postRawMime(
	url: string,
	rawMime: string,
	headers: Record< string, string > = {}
): Promise< Response > {
	return fetch( new URL( url, BASE_URL ).href, {
		method: 'POST',
		headers: {
			'Content-Type': 'message/rfc822',
			'X-Envelope-From': 'sender@example.com',
			'X-Envelope-To': 'mailbox@example.org',
			'X-Message-Raw-Size': String( Buffer.byteLength( rawMime ) ),
			...headers,
		},
		body: rawMime,
	} );
}

test.beforeAll( async ( { requestUtils } ) => {
	// Create an application password the way the worker's setup flow ends up with one.
	// wp-env is WP_ENVIRONMENT_TYPE=local, so application passwords work over plain HTTP.
	const response = await requestUtils.rest< { uuid: string; password: string } >( {
		path: '/wp/v2/users/me/application-passwords',
		method: 'POST',
		data: { name: `e2e-rest-ingress-${ Date.now() }` },
	} );
	applicationPassword = response.password;
	applicationPasswordUuid = response.uuid;
} );

test.afterAll( async ( { requestUtils } ) => {
	if ( applicationPasswordUuid ) {
		await requestUtils.rest( {
			path: `/wp/v2/users/me/application-passwords/${ applicationPasswordUuid }`,
			method: 'DELETE',
		} );
	}
} );

test.describe( 'REST ingress — discovery', () => {
	test( 'the unauthenticated REST index advertises exactly one ingress endpoint', async () => {
		const response = await fetch( new URL( '/wp-json/', BASE_URL ).href );
		expect( response.status ).toBe( 200 );

		const index = await response.json();
		expect( Array.isArray( index.email_ingress_endpoints ) ).toBe( true );
		// The worker treats more than one advertised endpoint as a configuration error.
		expect( index.email_ingress_endpoints ).toHaveLength( 1 );

		const entry = index.email_ingress_endpoints[ 0 ];
		expect( entry.version ).toBe( 1 );
		expect( entry.namespace ).toBe( DEV_INGRESS_NAMESPACE );
		expect( entry.url ).toContain( INGRESS_PATH );
		expect( entry.accepts ).toBe( 'message/rfc822' );
		expect( entry.max_message_size_bytes ).toBeGreaterThan( 0 );
	} );
} );

test.describe( 'REST ingress — delivery', () => {
	test( 'a raw MIME POST with application-password Basic auth saves the email and is idempotent', async ( {
		admin,
		page,
	} ) => {
		// Discover the endpoint the way the worker does, and use the discovered URL.
		const index = await ( await fetch( new URL( '/wp-json/', BASE_URL ).href ) ).json();
		const ingressUrl: string = index.email_ingress_endpoints[ 0 ].url;

		const fixture = uniquifiedFixture( 'plain-text-simple.eml' );
		const authorization = basicAuthHeader( applicationPassword );

		// First delivery: created.
		const first = await postRawMime( ingressUrl, fixture.raw, { Authorization: authorization } );
		expect( first.status ).toBe( 201 );
		const firstBody = await first.json();
		expect( firstBody.post_id ).toBeGreaterThan( 0 );
		// The plugin strips the angle brackets from the Message-ID header.
		expect( `<${ firstBody.message_id }>` ).toBe( fixture.messageId );

		// Redelivery (worker/SMTP retry): 200, same post, no duplicate.
		const second = await postRawMime( ingressUrl, fixture.raw, { Authorization: authorization } );
		expect( second.status ).toBe( 200 );
		expect( ( await second.json() ).post_id ).toBe( firstBody.post_id );

		// The email is visible in the admin list exactly once.
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&s=${ encodeURIComponent( fixture.subject ) }` );
		await expect( page.locator( `#post-${ firstBody.post_id }` ) ).toBeVisible();
		await expect( page.locator( '#the-list tr.type-e2e_email' ) ).toHaveCount( 1 );
	} );

	test( 'a multipart message with an attachment is accepted', async ( { admin, page } ) => {
		const fixture = uniquifiedFixture( 'multipart-with-attachment.eml' );

		const response = await postRawMime( INGRESS_PATH, fixture.raw, {
			Authorization: basicAuthHeader( applicationPassword ),
		} );
		expect( response.status ).toBe( 201 );
		const postId = ( await response.json() ).post_id;

		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }&s=${ encodeURIComponent( fixture.subject ) }` );
		await expect( page.locator( `#post-${ postId }` ) ).toBeVisible();
	} );
} );

test.describe( 'REST ingress — auth failures', () => {
	test( 'an unauthenticated POST is rejected with 401', async () => {
		const fixture = uniquifiedFixture( 'plain-text-simple.eml' );

		const response = await postRawMime( INGRESS_PATH, fixture.raw );
		expect( response.status ).toBe( 401 );
	} );

	test( 'a POST with an invalid application password is rejected with 401', async () => {
		const fixture = uniquifiedFixture( 'plain-text-simple.eml' );

		const response = await postRawMime( INGRESS_PATH, fixture.raw, {
			Authorization: basicAuthHeader( 'definitely-not-the-password' ),
		} );
		expect( response.status ).toBe( 401 );
	} );
} );

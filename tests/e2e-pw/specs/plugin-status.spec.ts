/**
 * Basic end-to-end test for bh-wp-mailboxes.
 *
 * Demonstrates the conventional approach: arrange and assert via the development-plugin's REST
 * endpoints, and use the UI only to confirm the admin list page renders. Run against wp-env:
 *
 *   npm run wp-env:start
 *   npm run test:e2e
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

test.describe( 'bh-wp-mailboxes', () => {
	test( 'library loads and the test-plugin is active', async ( { request } ) => {
		// Assert via REST (no UI needed for this check).
		const response = await request.get( `${ DEV_REST }/status` );
		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body.library_loaded ).toBe( true );
		expect( typeof body.email_count ).toBe( 'number' );
	} );

	test( 'a created email appears in the admin list', async ( { admin, page, request } ) => {
		// Arrange: create a fixture email via REST rather than through the UI.
		const subject = `E2E ${ Date.now() }`;
		const create = await request.post( `${ DEV_REST }/emails`, {
			data: { subject },
		} );
		expect( create.status() ).toBe( 201 );

		// Act (minimal UI): open the emails admin list.
		await admin.visitAdminPage( 'edit.php', 'post_type=bh_wp_mailboxes_cpt' );

		// Assert the fixture is visible in the list.
		await expect( page.getByText( subject ) ).toBeVisible();
	} );
} );

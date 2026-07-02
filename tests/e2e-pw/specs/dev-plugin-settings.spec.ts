/**
 * The development plugin's settings page.
 *
 * It is the top-level "Mailboxes" menu's target and first submenu, and shows the IMAP test-mailbox
 * credentials form, the email-fetch cron status with a "run now" button, and the registered custom
 * post types and their statuses.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Development plugin settings page', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=development-plugin-settings'
		);
	} );

	test( 'is reachable as the first submenu of the Mailboxes menu', async ( {
		page,
	} ) => {
		const firstSubmenu = page.locator(
			'#adminmenu li.menu-top:has(> a.menu-top[href="admin.php?page=development-plugin-settings"]) .wp-submenu li a'
		);
		await expect( firstSubmenu.first() ).toHaveText( 'Settings' );
	} );

	test( 'shows the IMAP credentials form', async ( { page } ) => {
		await expect(
			page.getByRole( 'heading', { name: 'IMAP test mailbox' } )
		).toBeVisible();

		await expect( page.locator( '#imap_server' ) ).toBeVisible();
		await expect( page.locator( '#imap_username' ) ).toBeVisible();
		await expect( page.locator( '#imap_password' ) ).toBeVisible();
		await expect( page.locator( '#imap_encryption' ) ).toBeVisible();
	} );

	test( 'shows the cron status with a run-now button', async ( { page } ) => {
		await expect(
			page.getByRole( 'heading', { name: 'Email fetch cron' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Fetch emails now' } )
		).toBeVisible();
	} );

	test( 'lists the registered post types and their statuses', async ( {
		page,
	} ) => {
		await expect(
			page.getByRole( 'heading', { name: 'Registered post types' } )
		).toBeVisible();
		// The library's three email statuses are documented on the page.
		await expect( page.locator( 'body' ) ).toContainText( 'bh_email_new' );
		await expect( page.locator( 'body' ) ).toContainText(
			'bh_email_saved'
		);
	} );
} );

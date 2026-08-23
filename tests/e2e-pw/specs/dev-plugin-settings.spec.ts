/**
 * The development plugin's settings page.
 *
 * It is the top-level "Mailboxes" menu's target and first submenu, and configures the two empty demo
 * mailboxes: per-mailbox REST enablement, an IMAP account from `.env.secret` or typed-in credentials,
 * and a Gmail account from credential files or pasted JSON. It also shows the email-fetch cron status
 * with a "run now" button, and the registered custom post types and their statuses.
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

	test( 'lists the two demo mailboxes with per-mailbox REST checkboxes', async ( {
		page,
	} ) => {
		await expect(
			page.getByRole( 'heading', { name: 'Mailboxes', exact: true } )
		).toBeVisible();

		await expect( page.locator( 'body' ) ).toContainText(
			'Mailbox One Email'
		);
		await expect( page.locator( 'body' ) ).toContainText(
			'Mailbox Two Email'
		);

		await expect(
			page.locator( '#rest_enabled_mailbox-one' )
		).toBeVisible();
		await expect(
			page.locator( '#rest_enabled_mailbox-two' )
		).toBeVisible();
	} );

	test( 'saves the per-mailbox REST setting', async ( { admin, page } ) => {
		const checkbox = page.locator( '#rest_enabled_mailbox-one' );
		await checkbox.check();
		await page
			.getByRole( 'button', { name: 'Save REST settings' } )
			.click();

		await expect( page.locator( '.notice-success' ) ).toContainText(
			'REST settings saved'
		);
		await expect(
			page.locator( '#rest_enabled_mailbox-one' )
		).toBeChecked();

		// Restore the default so other specs see the mailbox without REST. Submitted with fetch()
		// rather than a second button click: after an admin-post redirect, headless Chromium stops
		// producing frames, so Playwright's click actionability (rAF-based stability) hangs forever.
		await page.evaluate( () => {
			const form = document
				.querySelector( '#rest_enabled_mailbox-one' )
				.closest( 'form' );
			const data = new FormData( form );
			data.delete( 'rest_enabled[]' );
			return fetch( form.getAttribute( 'action' ), {
				method: 'POST',
				body: data,
				credentials: 'same-origin',
			} );
		} );

		await admin.visitAdminPage(
			'admin.php',
			'page=development-plugin-settings'
		);
		await expect(
			page.locator( '#rest_enabled_mailbox-one' )
		).not.toBeChecked();
	} );

	test( 'shows the .env.secret section', async ( { page } ) => {
		await expect(
			page.getByRole( 'heading', { name: '.env.secret IMAP account' } )
		).toBeVisible();
	} );

	test( 'shows the IMAP credentials form with a mailbox dropdown', async ( {
		page,
	} ) => {
		await expect(
			page.getByRole( 'heading', { name: 'IMAP setup' } )
		).toBeVisible();

		await expect( page.locator( '#imap_server' ) ).toBeVisible();
		await expect( page.locator( '#imap_username' ) ).toBeVisible();
		await expect( page.locator( '#imap_password' ) ).toBeVisible();
		await expect( page.locator( '#imap_encryption' ) ).toBeVisible();

		const mailboxSelect = page.locator( '#imap_mailbox' );
		await expect( mailboxSelect ).toBeVisible();
		await expect(
			mailboxSelect.locator( 'option[value=""]' )
		).toHaveText( 'None' );
		await expect(
			mailboxSelect.locator( 'option[value="mailbox-one"]' )
		).toHaveText( 'Mailbox One Email' );
		await expect(
			mailboxSelect.locator( 'option[value="mailbox-two"]' )
		).toHaveText( 'Mailbox Two Email' );
	} );

	test( 'shows the Gmail pasted-credentials form with a mailbox dropdown', async ( {
		page,
	} ) => {
		await expect(
			page.getByRole( 'heading', { name: 'Gmail', exact: true } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Pasted credentials' } )
		).toBeVisible();

		await expect( page.locator( '#gmail_email_address' ) ).toBeVisible();
		await expect(
			page.locator( '#gmail_client_secret_json' )
		).toBeVisible();
		await expect(
			page.locator( '#gmail_access_token_json' )
		).toBeVisible();
		await expect( page.locator( '#gmail_mailbox' ) ).toBeVisible();
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
		// The two empty demo mailboxes' CPTs are documented on the page.
		await expect( page.locator( 'body' ) ).toContainText(
			'mailbox_one_email'
		);
		await expect( page.locator( 'body' ) ).toContainText(
			'mailbox_two_email'
		);
		// The library's email statuses are documented on the page.
		await expect( page.locator( 'body' ) ).toContainText( 'bh_email_new' );
		await expect( page.locator( 'body' ) ).toContainText(
			'bh_email_saved'
		);
	} );
} );

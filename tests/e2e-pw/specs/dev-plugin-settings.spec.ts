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
		await expect( page.locator( '#imap_validate_cert' ) ).toBeVisible();
		await expect( page.locator( '#imap_validate_cert' ) ).toBeChecked();

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

	test( 'the reusable account modal on the settings page adds an account to Mailbox One', async ( {
		admin,
		page,
	} ) => {
		const emailAddress = `settings-modal-${ Date.now() }@example.com`;

		await expect(
			page.getByRole( 'heading', { name: 'Add IMAP account (modal)' } )
		).toBeVisible();
		const section = page.locator( '.bh-dev-modal-section' );
		await section.getByRole( 'button', { name: 'Add account' } ).click();

		const dialog = page.locator( '#bh-mailboxes-account-dialog' );
		await expect( dialog ).toBeVisible();
		await expect(
			dialog.getByRole( 'heading', { name: 'Add IMAP account' } )
		).toBeVisible();
		await dialog.getByLabel( 'Account name' ).fill( 'Settings modal inbox' );
		await dialog.getByLabel( 'Email address' ).fill( emailAddress );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await dialog.getByLabel( 'Encryption' ).selectOption( '' );
		await dialog.getByRole( 'button', { name: 'Add account' } ).click();
		await expect( dialog ).toBeHidden();

		// No accounts table on this screen: the result is reported in a notice under the title.
		const notice = page.locator( '.bh-check-notice' ).last();
		await expect( notice ).toContainText(
			'Account saved, but the connection test failed'
		);
		expect(
			await notice.evaluate( ( el ) =>
				el.previousElementSibling?.classList.contains( 'wp-header-end' )
			)
		).toBe( true );

		// The account is listed in Mailbox One's accounts table, with its credentials stored.
		await admin.visitAdminPage( 'edit.php', 'post_type=mailbox_one_email' );
		const row = page.locator(
			`.bh-mailboxes-account[data-email-address="${ emailAddress }"]`
		);
		await expect( row ).toBeVisible();
		await expect( row ).toContainText( 'Settings modal inbox' );
		await expect( row ).toContainText( 'IMAP' );
		await expect( row.locator( '.bh-mailboxes-no-credentials' ) ).toHaveCount(
			0
		);

		// Clean up so Mailbox One's cron does not keep trying the unreachable server.
		await row.hover();
		await row.getByRole( 'link', { name: 'Delete', exact: true } ).click();
		const confirm = page.locator( '#bh-mailboxes-account-confirm' );
		await confirm.getByRole( 'button', { name: 'Delete account' } ).click();
		await expect( row ).toHaveCount( 0 );
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

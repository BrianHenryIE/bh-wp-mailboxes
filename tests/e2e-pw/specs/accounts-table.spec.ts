/**
 * Playwright tests for the accounts table's add/edit modal and enable/disable/delete actions.
 *
 * The IMAP server used (127.0.0.1:1) refuses connections immediately, so the connection test fails
 * fast. The library saves the credentials in the WordPress Secrets API, which is what lets the edit
 * form pre-fill and the password be kept on edit.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Locator, Page } from '@playwright/test';

const EMAILS_LIST = 'post_type=e2e_email';
const INGRESS_URL = '/wp-json/bh-wp-mailboxes-dev/v2/e2e-email/new';
/** The ingress account's address: `{rest namespace}@{site host}` (see REST_Ingress_Connection::get_email_account_wp_post_for_mailbox()). */
const INGRESS_ACCOUNT_EMAIL = `bh-wp-mailboxes-dev@${
	new URL( process.env.BASEURL || process.env.WP_BASE_URL || 'http://localhost:8888' ).hostname
}`;

function accountRow( page: Page, emailAddress: string ) {
	return page.locator( `.bh-mailboxes-account[data-email-address="${ emailAddress }"]` );
}

/** Row actions are links revealed on row hover (WP_List_Table's `.row-actions`). */
async function clickRowAction( row: Locator, name: string ) {
	await row.hover();
	await row.getByRole( 'link', { name, exact: true } ).click();
}

async function openAddDialog( page: Page ) {
	await page.getByRole( 'button', { name: 'Add account' } ).click();
	const dialog = page.locator( '#bh-mailboxes-account-dialog' );
	await expect( dialog ).toBeVisible();
	return dialog;
}

async function addImapAccount( page: Page, emailAddress: string, displayName: string ) {
	const dialog = await openAddDialog( page );
	await dialog.getByLabel( 'Account name' ).fill( displayName );
	await dialog.getByLabel( 'Email address' ).fill( emailAddress );
	await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
	await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
	await dialog.getByLabel( 'Encryption' ).selectOption( '' );
	await dialog.getByRole( 'button', { name: 'Add account' } ).click();
	await expect( dialog ).toBeHidden();
	const row = accountRow( page, emailAddress );
	await expect( row ).toBeVisible();
	return row;
}

test.describe( 'accounts table — add / edit / enable / delete', () => {
	test( 'an IMAP account added in the modal is listed, editable, and keeps its password on edit', async ( {
		admin,
		page,
	} ) => {
		const emailAddress = `modal-add-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );

		const row = await addImapAccount( page, emailAddress, 'Modal inbox' );

		// Saved despite the (unreachable) server failing the connection test.
		await expect( page.locator( '.bh-check-notice' ).last() ).toContainText(
			'Account saved, but the connection test failed'
		);
		await expect( row ).toContainText( 'Modal inbox' );
		await expect( row ).toContainText( 'IMAP' );
		await expect( row.locator( '.bh-mailboxes-badge' ) ).toHaveText( 'Active' );
		await expect( row.locator( '.bh-mailboxes-no-credentials' ) ).toHaveCount( 0 );

		// Edit: the form is pre-filled from the stored credentials, password blank.
		await clickRowAction( row, 'Edit' );
		const dialog = page.locator( '#bh-mailboxes-account-dialog' );
		await expect( dialog.getByRole( 'heading', { name: 'Edit IMAP account' } ) ).toBeVisible();
		await expect( dialog.getByLabel( 'Email address' ) ).toHaveValue( emailAddress );
		await expect( dialog.getByLabel( 'Email address' ) ).toHaveAttribute( 'readonly', '' );
		await expect( dialog.getByLabel( 'IMAP server' ) ).toHaveValue( '127.0.0.1:1' );
		await expect( dialog.getByLabel( 'Username' ) ).toHaveValue( emailAddress );
		await expect( dialog.getByLabel( 'Password' ) ).toHaveValue( '' );
		await expect( dialog.getByText( 'Leave blank to keep the saved password.' ) ).toBeVisible();

		await dialog.getByLabel( 'Account name' ).fill( 'Renamed inbox' );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:2' );
		await dialog.getByLabel( 'Encryption' ).selectOption( 'STARTTLS' );
		await dialog.getByRole( 'button', { name: 'Save account' } ).click();
		await expect( dialog ).toBeHidden();

		const edited = accountRow( page, emailAddress );
		await expect( edited ).toContainText( 'Renamed inbox' );
		await expect( edited.locator( '.bh-mailboxes-no-credentials' ) ).toHaveCount( 0 );

		// The edited credentials persist across a reload.
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		await clickRowAction( accountRow( page, emailAddress ), 'Edit' );
		await expect( dialog.getByLabel( 'IMAP server' ) ).toHaveValue( '127.0.0.1:2' );
		await expect( dialog.getByLabel( 'Encryption' ) ).toHaveValue( 'STARTTLS' );
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();
	} );

	test( 'an account can be disabled and re-enabled without deleting it', async ( { admin, page } ) => {
		const emailAddress = `modal-toggle-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = await addImapAccount( page, emailAddress, 'Toggle inbox' );

		await clickRowAction( row, 'Disable' );
		await expect( accountRow( page, emailAddress ).locator( '.bh-mailboxes-badge' ) ).toHaveText( 'Inactive' );
		await expect( page.locator( '.bh-check-notice' ).last() ).toContainText( `${ emailAddress } disabled.` );

		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		await expect( accountRow( page, emailAddress ).locator( '.bh-mailboxes-badge' ) ).toHaveText( 'Inactive' );

		await clickRowAction( accountRow( page, emailAddress ), 'Enable' );
		await expect( accountRow( page, emailAddress ).locator( '.bh-mailboxes-badge' ) ).toHaveText( 'Active' );
		await expect( page.locator( '.bh-check-notice' ).last() ).toContainText( `${ emailAddress } enabled.` );
	} );

	test( 'deleting an account asks for confirmation in a dialog', async ( { admin, page } ) => {
		const emailAddress = `modal-delete-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = await addImapAccount( page, emailAddress, 'Delete inbox' );

		const confirm = page.locator( '#bh-mailboxes-account-confirm' );
		await clickRowAction( row, 'Delete' );
		await expect( confirm ).toBeVisible();
		await expect( confirm ).toContainText( `Delete ${ emailAddress }?` );
		await confirm.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( confirm ).toBeHidden();
		await expect( row ).toBeVisible();

		// While the delete request is in flight the confirm button is disabled (no double submit).
		await page.route( '**/admin-ajax.php', async ( route ) => {
			if ( route.request().postData()?.includes( 'delete_account' ) ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 800 ) );
			}
			await route.continue();
		} );
		await clickRowAction( row, 'Delete' );
		await confirm.getByRole( 'button', { name: 'Delete account' } ).click();
		await expect( confirm.getByRole( 'button', { name: 'Delete account' } ) ).toBeDisabled();
		await expect( confirm ).toBeHidden();
		await expect( accountRow( page, emailAddress ) ).toHaveCount( 0 );
		await expect( page.locator( '.bh-check-notice' ).last() ).toContainText( `${ emailAddress } deleted` );
	} );

	test( 'the receive-only REST ingress account shows N/A, and can be disabled but not checked, edited or deleted', async ( {
		admin,
		page,
		request,
	} ) => {
		// Auto-create the ingress account by delivering an email to the endpoint.
		const nonceResponse = await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' );
		const nonce = ( await nonceResponse.text() ).trim();
		const raw =
			'From: a@example.com\r\nTo: b@example.com\r\nSubject: Hi\r\n' +
			`Message-ID: <accounts-table-${ Date.now() }@example.com>\r\n\r\nHello\r\n`;
		const delivered = await request.post( INGRESS_URL, {
			headers: { 'Content-Type': 'message/rfc822', 'X-WP-Nonce': nonce },
			data: raw,
		} );
		expect( delivered.status() ).toBe( 201 );

		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = accountRow( page, INGRESS_ACCOUNT_EMAIL );
		await expect( row ).toBeVisible();
		await expect( row ).toHaveAttribute( 'data-supports-fetching', '0' );
		await expect( row ).toContainText( 'REST Ingress' );
		await expect( row.locator( '[data-field="last-fetched"]' ) ).toHaveText( 'N/A' );
		await expect( row.locator( '[data-field="last-failure"]' ) ).toHaveText( 'N/A' );
		await expect( row.locator( '.bh-check-account' ) ).toHaveCount( 0 );
		await expect( row.locator( '.bh-account-edit' ) ).toHaveCount( 0 );
		await expect( row.locator( '.bh-account-delete' ) ).toHaveCount( 0 );
		await expect( row ).toHaveAttribute( 'data-can-edit', '0' );
		await row.hover();
		await expect( row.getByRole( 'link', { name: 'Disable' } ) ).toBeVisible();

		// The save handler is keyed by email address: posting the ingress account's address must not
		// convert it into an IMAP account.
		const hijack = await page.evaluate( async ( emailAddress ) => {
			const body = new URLSearchParams( {
				action: ( window as any ).bh_wp_mailboxes_ajax.save_account_action,
				_wpnonce: ( document.getElementById( '_wpnonce_account_actions' ) as HTMLInputElement ).value,
				email_address: emailAddress,
				display_name: 'Hijacked',
				server: '127.0.0.1:1',
				password: 'x',
				encryption: 'TLS',
			} );
			const response = await fetch( ( window as any ).ajaxurl, { method: 'POST', body, credentials: 'same-origin' } );
			return { status: response.status, json: await response.json() };
		}, INGRESS_ACCOUNT_EMAIL );
		expect( hijack.status ).toBe( 400 );
		// On a dotless host (localhost) the address fails is_email() first; elsewhere the IMAP guard rejects it.
		expect( hijack.json.data.message ).toMatch( /not an IMAP account|valid email address is required/ );

		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const after = accountRow( page, INGRESS_ACCOUNT_EMAIL );
		await expect( after ).toContainText( 'REST Ingress' );
		await expect( after ).not.toContainText( 'Hijacked' );
	} );

	test( 'the add dialog can be closed with the × button, Cancel, and Escape', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );

		let dialog = await openAddDialog( page );
		await dialog.locator( '.bh-mailboxes-account-dialog__close' ).click();
		await expect( dialog ).toBeHidden();

		dialog = await openAddDialog( page );
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();

		dialog = await openAddDialog( page );
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	} );

	test( 'a fresh add dialog defaults to TLS, requires email, server and password, and hides the edit-only hints', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const dialog = await openAddDialog( page );

		await expect( dialog.getByRole( 'heading', { name: 'Add IMAP account' } ) ).toBeVisible();
		await expect( dialog.getByRole( 'button', { name: 'Add account' } ) ).toBeVisible();
		await expect( dialog.getByLabel( 'Encryption' ) ).toHaveValue( 'TLS' );
		await expect( dialog.getByLabel( "Validate the server's certificate" ) ).toBeChecked();
		await expect( dialog.getByLabel( 'Email address' ) ).not.toHaveAttribute( 'readonly', '' );
		await expect( dialog.getByLabel( 'Account name' ) ).toBeFocused();
		await expect( dialog.locator( '.bh-mailboxes-account-form__edit-only' ) ).toHaveCount( 2 );
		await expect( dialog.locator( '.bh-mailboxes-account-form__edit-only' ).first() ).toBeHidden();
		await expect( dialog.locator( '.bh-mailboxes-account-form__edit-only' ).last() ).toBeHidden();

		// Submitting empty is blocked by the browser's required-field validation; the dialog stays open.
		await dialog.getByRole( 'button', { name: 'Add account' } ).click();
		await expect( dialog ).toBeVisible();
		for ( const label of [ 'Email address', 'IMAP server', 'Password' ] ) {
			expect(
				await dialog.getByLabel( label ).evaluate( ( el ) => ( el as HTMLInputElement ).validity.valueMissing ),
				`${ label } should be required`
			).toBe( true );
		}
		expect(
			await dialog.getByLabel( 'Username' ).evaluate( ( el ) => ( el as HTMLInputElement ).validity.valueMissing )
		).toBe( false );
	} );

	test( 'server-side validation errors show in the form notice and keep the dialog open', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const dialog = await openAddDialog( page );

		await dialog.getByLabel( 'Email address' ).fill( `modal-invalid-${ Date.now() }@example.com` );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		// Bypass the browser's select constraint so the server-side check is what rejects it.
		await dialog.getByLabel( 'Encryption' ).evaluate( ( el ) => {
			const option = document.createElement( 'option' );
			option.value = 'ROT13';
			( el as HTMLSelectElement ).append( option );
			( el as HTMLSelectElement ).value = 'ROT13';
		} );
		await dialog.getByRole( 'button', { name: 'Add account' } ).click();

		const notice = dialog.locator( '.bh-mailboxes-account-form__notice' );
		await expect( notice ).toBeVisible();
		await expect( notice ).toHaveClass( /notice-error/ );
		await expect( notice ).toContainText( 'Encryption must be TLS, STARTTLS or none.' );
		await expect( dialog ).toBeVisible();
		await expect( dialog.getByRole( 'button', { name: 'Add account' } ) ).toBeEnabled();
	} );

	test( 'an explicit username, "no encryption" and an unticked certificate check round-trip to the edit form', async ( { admin, page } ) => {
		const emailAddress = `modal-username-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );

		const dialog = await openAddDialog( page );
		await dialog.getByLabel( 'Email address' ).fill( emailAddress );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Username' ).fill( 'login-name' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await dialog.getByLabel( 'Encryption' ).selectOption( '' );
		await dialog.getByLabel( "Validate the server's certificate" ).uncheck();
		await dialog.getByRole( 'button', { name: 'Add account' } ).click();
		await expect( dialog ).toBeHidden();

		const row = accountRow( page, emailAddress );
		await expect( row ).toBeVisible();
		// The display name defaults to the address.
		await expect( row ).toContainText( emailAddress );
		await expect( row ).toHaveAttribute( 'data-validate-cert', '0' );

		await clickRowAction( row, 'Edit' );
		await expect( dialog.getByLabel( 'Username' ) ).toHaveValue( 'login-name' );
		await expect( dialog.getByLabel( 'Encryption' ) ).toHaveValue( '' );
		await expect( dialog.getByLabel( "Validate the server's certificate" ) ).not.toBeChecked();
		await expect( dialog.getByText( 'The email address identifies the account and cannot be changed.' ) ).toBeVisible();
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();
	} );

	test( 'saving shows a busy state until the response arrives', async ( { admin, page } ) => {
		const emailAddress = `modal-busy-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		await page.route( '**/admin-ajax.php', async ( route ) => {
			if ( route.request().postData()?.includes( 'save_account' ) ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 800 ) );
			}
			await route.continue();
		} );

		const dialog = await openAddDialog( page );
		await dialog.getByLabel( 'Email address' ).fill( emailAddress );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await dialog.getByRole( 'button', { name: 'Add account' } ).click();

		const submit = dialog.locator( '.bh-mailboxes-account-form__submit' );
		await expect( submit ).toBeDisabled();
		await expect( submit ).toHaveText( 'Saving…' );
		await expect( dialog.locator( '.spinner.is-active' ) ).toBeVisible();

		await expect( dialog ).toBeHidden();
		await expect( accountRow( page, emailAddress ) ).toBeVisible();
	} );

	test( 'an add dialog opened after a cancelled edit is reset', async ( { admin, page } ) => {
		const emailAddress = `modal-reset-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = await addImapAccount( page, emailAddress, 'Reset inbox' );

		const dialog = page.locator( '#bh-mailboxes-account-dialog' );
		await clickRowAction( row, 'Edit' );
		await expect( dialog.getByLabel( 'Account name' ) ).toHaveValue( 'Reset inbox' );
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();

		await openAddDialog( page );
		await expect( dialog.getByRole( 'heading', { name: 'Add IMAP account' } ) ).toBeVisible();
		await expect( dialog.locator( '.bh-mailboxes-account-form__submit' ) ).toHaveText( 'Add account' );
		await expect( dialog.getByLabel( 'Account name' ) ).toHaveValue( '' );
		await expect( dialog.getByLabel( 'Email address' ) ).toHaveValue( '' );
		await expect( dialog.getByLabel( 'Email address' ) ).not.toHaveAttribute( 'readonly', '' );
		await expect( dialog.getByLabel( 'IMAP server' ) ).toHaveValue( '' );
		await expect( dialog.getByLabel( 'Username' ) ).toHaveValue( '' );
		await expect( dialog.getByLabel( 'Encryption' ) ).toHaveValue( 'TLS' );
		await expect( dialog.locator( '[name="account_post_id"]' ) ).toHaveValue( '' );
	} );

	test( '"Test connection" reports the server\'s error in the form without saving the account', async ( {
		admin,
		page,
	} ) => {
		const emailAddress = `modal-test-fail-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const dialog = await openAddDialog( page );
		await dialog.getByLabel( 'Email address' ).fill( emailAddress );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await dialog.getByLabel( 'Encryption' ).selectOption( '' );

		await dialog.getByRole( 'button', { name: 'Test connection' } ).click();

		const notice = dialog.locator( '.bh-mailboxes-account-form__notice' );
		await expect( notice ).toBeVisible();
		await expect( notice ).toHaveClass( /notice-error/ );
		await expect( notice ).not.toHaveText( '' );
		// The dialog stays open with the entered values, and nothing was saved.
		await expect( dialog ).toBeVisible();
		await expect( dialog.getByLabel( 'IMAP server' ) ).toHaveValue( '127.0.0.1:1' );
		await expect( accountRow( page, emailAddress ) ).toHaveCount( 0 );

		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();
	} );

	test( '"Test connection" requires the same fields as saving, and shows a busy state', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		await page.route( '**/admin-ajax.php', async ( route ) => {
			if ( route.request().postData()?.includes( 'test_account_connection' ) ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 800 ) );
			}
			await route.continue();
		} );

		const dialog = await openAddDialog( page );
		// By class, not by name: the label changes to "Testing…" while the request is in flight.
		const testButton = dialog.locator( '.bh-mailboxes-account-form__test' );

		// Nothing entered: the browser's required-field validation stops the request.
		await testButton.click();
		expect( await dialog.getByLabel( 'Email address' ).evaluate( ( el: HTMLInputElement ) => el.validity.valueMissing ) ).toBe( true );
		await expect( dialog.locator( '.bh-mailboxes-account-form__notice' ) ).toBeHidden();

		await dialog.getByLabel( 'Email address' ).fill( `modal-test-busy-${ Date.now() }@example.com` );
		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await testButton.click();

		await expect( testButton ).toBeDisabled();
		await expect( testButton ).toHaveText( 'Testing…' );
		await expect( dialog.locator( '.bh-mailboxes-account-form__submit' ) ).toBeDisabled();
		await expect( dialog.locator( '.spinner.is-active' ) ).toBeVisible();

		await expect( dialog.locator( '.bh-mailboxes-account-form__notice' ) ).toBeVisible();
		await expect( testButton ).toBeEnabled();
		await expect( testButton ).toHaveText( 'Test connection' );
		await expect( dialog.locator( '.bh-mailboxes-account-form__submit' ) ).toBeEnabled();
	} );

	test( '"Test connection" in the edit dialog works with a blank password (the saved one is used)', async ( {
		admin,
		page,
	} ) => {
		const emailAddress = `modal-test-edit-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = await addImapAccount( page, emailAddress, 'Test on edit' );

		const dialog = page.locator( '#bh-mailboxes-account-dialog' );
		await clickRowAction( row, 'Edit' );
		await expect( dialog.getByLabel( 'Password' ) ).toHaveValue( '' );

		await dialog.getByRole( 'button', { name: 'Test connection' } ).click();

		// The saved (unreachable) server is tested, so the result is an error rather than a validation refusal.
		const notice = dialog.locator( '.bh-mailboxes-account-form__notice' );
		await expect( notice ).toBeVisible();
		await expect( notice ).toHaveClass( /notice-error/ );
		await expect( notice ).not.toContainText( 'password is required' );
		await expect( dialog ).toBeVisible();
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
	} );

	test( '"Check now" against an unreachable IMAP server shows a red failure notice with the server error and records the failure', async ( {
		admin,
		page,
	} ) => {
		const emailAddress = `check-unreachable-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = await addImapAccount( page, emailAddress, 'Unreachable inbox' );
		await expect( row.locator( '[data-field="last-failure"]' ) ).toHaveText( 'Never' );

		await clickRowAction( row, 'Check now' );

		// The notice is an error (red), says the check failed, and carries the connection's own message.
		const notice = page.locator( `.bh-check-notice[data-account-id="${ await row.getAttribute( 'data-account-id' ) }"]` );
		await expect( notice ).toContainText( 'Unreachable inbox: Check failed. Could not fetch emails:' );
		await expect( notice.locator( '.spinner' ) ).not.toBeAttached();
		await expect( notice ).toHaveCSS( 'border-left-color', 'rgb(214, 54, 56)' ); // #d63638

		// The row reflects the failure without a reload: last failure "Just now", and the login-failure badge.
		const updated = accountRow( page, emailAddress );
		await expect( updated.locator( '[data-field="last-failure"]' ) ).toHaveText( 'Just now' );
		await expect( updated.locator( '[data-field="last-fetched"]' ) ).toContainText( 'Never' );
		await expect( updated.locator( '.bh-mailboxes-login-failure' ) ).toBeVisible();
	} );

	test( '"Check now" on an IMAP account without stored credentials fails, saying so', async ( {
		admin,
		page,
		request,
	} ) => {
		const emailAddress = `check-no-credentials-${ Date.now() }@example.com`;
		const created = await request.post( '/wp-json/bh-wp-mailboxes-dev/v2/accounts', {
			data: { email_address: emailAddress, display_name: 'Credential-less inbox', connection: 'imap' },
		} );
		expect( created.status() ).toBe( 201 );
		const accountId = ( await created.json() ).post_id as number;

		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		await clickRowAction( accountRow( page, emailAddress ), 'Check now' );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ accountId }"]` );
		await expect( notice ).toContainText( 'Credential-less inbox: Check failed. No credentials are saved for this account.' );
		await expect( notice ).toHaveCSS( 'border-left-color', 'rgb(214, 54, 56)' ); // #d63638
	} );

	test( '"Check now" on a disabled account is reported as not checked (yellow), not as a success', async ( {
		admin,
		page,
	} ) => {
		const emailAddress = `check-disabled-${ Date.now() }@example.com`;
		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = await addImapAccount( page, emailAddress, 'Disabled inbox' );
		const accountId = await row.getAttribute( 'data-account-id' );

		await clickRowAction( row, 'Disable' );
		await expect( accountRow( page, emailAddress ).locator( '.bh-mailboxes-badge' ) ).toHaveText( 'Inactive' );

		await clickRowAction( accountRow( page, emailAddress ), 'Check now' );

		const notice = page.locator( `.bh-check-notice[data-account-id="${ accountId }"]` );
		await expect( notice ).toContainText( 'Disabled inbox: Not checked. The account is disabled.' );
		await expect( notice ).toHaveCSS( 'border-left-color', 'rgb(219, 166, 23)' ); // #dba617
		// Skipping is not a login failure.
		await expect( accountRow( page, emailAddress ).locator( '[data-field="last-failure"]' ) ).toHaveText( 'Never' );
	} );

	test( 'an IMAP account without stored credentials warns, requires a password on edit, and is fixed by saving', async ( {
		admin,
		page,
		request,
	} ) => {
		const emailAddress = `no-credentials-${ Date.now() }@example.com`;
		const created = await request.post( '/wp-json/bh-wp-mailboxes-dev/v2/accounts', {
			data: { email_address: emailAddress, display_name: 'No credentials', connection: 'imap' },
		} );
		expect( created.status() ).toBe( 201 );

		await admin.visitAdminPage( 'edit.php', EMAILS_LIST );
		const row = accountRow( page, emailAddress );
		await expect( row ).toBeVisible();
		await expect( row ).toHaveAttribute( 'data-has-credentials', '0' );
		await expect( row.locator( '.bh-mailboxes-no-credentials' ) ).toHaveCount( 1 );

		const dialog = page.locator( '#bh-mailboxes-account-dialog' );
		await clickRowAction( row, 'Edit' );
		await expect( dialog.getByLabel( 'IMAP server' ) ).toHaveValue( '' );
		await expect( dialog.getByLabel( 'Password' ) ).toHaveAttribute( 'required', '' );
		await expect( dialog.getByText( 'Leave blank to keep the saved password.' ) ).toBeHidden();

		await dialog.getByLabel( 'IMAP server' ).fill( '127.0.0.1:1' );
		await dialog.getByLabel( 'Password' ).fill( 'not-a-real-password' );
		await dialog.getByLabel( 'Encryption' ).selectOption( '' );
		await dialog.getByRole( 'button', { name: 'Save account' } ).click();
		await expect( dialog ).toBeHidden();

		const fixed = accountRow( page, emailAddress );
		await expect( fixed ).toHaveAttribute( 'data-has-credentials', '1' );
		await expect( fixed.locator( '.bh-mailboxes-no-credentials' ) ).toHaveCount( 0 );

		// Now that credentials are stored, editing no longer requires a password.
		await clickRowAction( fixed, 'Edit' );
		await expect( dialog.getByLabel( 'Password' ) ).not.toHaveAttribute( 'required', '' );
		await expect( dialog.getByText( 'Leave blank to keep the saved password.' ) ).toBeVisible();
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
	} );
} );

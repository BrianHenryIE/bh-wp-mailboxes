/**
 * The development plugin's "Mailboxes" top-level admin menu: position and styling.
 *
 * It should sit immediately below Dashboard and above Posts (with the core separator below it as a
 * spacer), and be tinted green so the dev/test-harness menu is obvious.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

// The top-level menu points at the IMAP/ENV mailbox emails list.
const MAILBOXES_HREF = 'edit.php?post_type=imap_email_env';

test.describe( 'Development plugin Mailboxes menu', () => {
	test( 'sits between Dashboard and Posts, with a separator above and below it', async ( {
		admin,
		page,
	} ) => {
		// The admin menu is identical on every admin screen; use the emails list page (the dashboard
		// surfaces an unrelated, pre-existing dev-plugin PHP notice).
		await admin.visitAdminPage( 'edit.php', 'post_type=imap_email_env' );

		const items = await page.evaluate( () =>
			[ ...document.querySelectorAll( '#adminmenu > li' ) ]
				.map( ( li ) => ( {
					text: (
						li.querySelector( '.wp-menu-name' )?.textContent || ''
					).trim(),
					separator: li.classList.contains( 'wp-menu-separator' ),
				} ) )
				.filter( ( item ) => item.text || item.separator )
		);

		const dashboardIndex = items.findIndex( ( i ) => i.text === 'Dashboard' );
		const mailboxesIndex = items.findIndex( ( i ) => i.text === 'Mailboxes' );
		const postsIndex = items.findIndex( ( i ) => i.text === 'Posts' );

		// A separator (spacer) sits directly above Mailboxes, between it and Dashboard.
		expect( items[ dashboardIndex + 1 ].separator ).toBe( true );
		expect( mailboxesIndex ).toBe( dashboardIndex + 2 );
		// A separator (spacer) sits directly below Mailboxes, before Posts.
		expect( items[ mailboxesIndex + 1 ].separator ).toBe( true );
		expect( postsIndex ).toBeGreaterThan( mailboxesIndex );
	} );

	test( 'has a green background', async ( { admin, page } ) => {
		// The admin menu is identical on every admin screen; use the emails list page (the dashboard
		// surfaces an unrelated, pre-existing dev-plugin PHP notice).
		await admin.visitAdminPage( 'edit.php', 'post_type=imap_email_env' );

		const anchor = page.locator(
			`#adminmenu a.menu-top[href="${ MAILBOXES_HREF }"]`
		);
		await expect( anchor ).toHaveCSS( 'background-color', 'rgb(0, 128, 0)' );

		const li = page.locator(
			`#adminmenu li.menu-top:has(> a.menu-top[href="${ MAILBOXES_HREF }"])`
		);
		await expect( li ).toHaveCSS( 'background-color', 'rgb(0, 128, 0)' );
	} );
} );

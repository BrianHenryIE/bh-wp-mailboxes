<?php
/**
 * Dev-only top-level "Mailboxes" admin menu listing every registered mailbox.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;

/**
 * Adds and styles the development "Mailboxes" admin menu.
 */
class Menu {

	/**
	 * Hook the menu registration and its styling into the admin.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_head', array( $this, 'add_menu_style' ) );
	}

	/**
	 * Tint the development "Mailboxes" top-level menu green so the test-harness menu is obvious. The item
	 * is matched by its anchor href (its generated id is derived from the slug) and styled via the parent
	 * <li> and the anchor itself.
	 */
	public function add_menu_style(): void {
		/**
		 * The mailbox instances registered by the library.
		 *
		 * @var BH_WP_Mailboxes[] $mailboxes
		 */
		$mailboxes = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), 'development-plugin' );
		// The top-level menu points at the IMAP emails list, so clicking "Mailboxes" lands somewhere useful.
		$first_mailbox_edit_href = 'edit.php?post_type=' . $mailboxes[0]->get_settings()->get_emails_cpt_underscored_20();

		$href = $first_mailbox_edit_href;
		// !important so the green wins over the admin colour scheme's current/hover menu states.
		echo '<style id="bh-wp-mailboxes-dev-menu-style">'
			. '#adminmenu li.menu-top:has( > a.menu-top[href="' . esc_attr( $href ) . '"] ),'
			. '#adminmenu li.menu-top:has( > a.menu-top[href="' . esc_attr( $href ) . '"] ) a.menu-top,'
			. '#adminmenu a.menu-top[href="' . esc_attr( $href ) . '"] { background-color: green !important; }'
			. '</style>';
	}

	/**
	 * Add a top-level "Mailboxes" menu with a submenu linking to the emails and accounts list for each
	 * configured mailbox (the IMAP/ENV mailbox and the fixtures mailbox).
	 */
	public function add_menus(): void {

		/**
		 * The mailbox instances registered by the library.
		 *
		 * @var BH_WP_Mailboxes[] $mailboxes
		 */
		$mailboxes            = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), 'development-plugin' );
		$all_mailbox_settings = array_map(
			fn( $mailbox ) => $mailbox->get_settings(),
			$mailboxes
		);

		// The top-level menu points at the IMAP emails list, so clicking "Mailboxes" lands somewhere useful.
		$first_mailbox_edit_href = 'edit.php?post_type=' . $mailboxes[0]->get_settings()->get_emails_cpt_underscored_20();

		// Position 3 places "Mailboxes" between Dashboard (2) and Posts (5); WordPress's core separator (4)
		// sits below it, and the custom separator added below (2.5) sits above it.
		add_menu_page(
			'Mailboxes',
			'Mailboxes',
			'manage_options',
			$first_mailbox_edit_href,
			'',
			'dashicons-email',
			3
		);

		// Add a spacer above "Mailboxes" (between it and Dashboard). WordPress renders any $menu entry
		// whose class list contains `wp-menu-separator` as a spacer; key 2.5 slots it between 2 and 3.
		global $menu;
		$menu['2.5'] = array( '', 'read', 'bh-mailboxes-separator-top', '', 'wp-menu-separator' );

		foreach ( $all_mailbox_settings as $mailbox_settings ) {
			add_submenu_page(
				$first_mailbox_edit_href,
				$mailbox_settings->get_emails_cpt_friendly_name(),
				$mailbox_settings->get_emails_cpt_friendly_name(),
				'manage_options',
				'edit.php?post_type=' . $mailbox_settings->get_emails_cpt_underscored_20()
			);
		}
	}
}

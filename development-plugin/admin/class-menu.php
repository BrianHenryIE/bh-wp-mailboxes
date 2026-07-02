<?php
/**
 * Dev-only top-level "Mailboxes" admin menu: settings page plus a link per registered mailbox.
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
	 * Constructor.
	 *
	 * @param Settings $settings_page The settings page (the menu's top-level target and first submenu).
	 */
	public function __construct(
		protected Settings $settings_page,
	) {
	}

	/**
	 * Hook the menu registration and its styling into the admin.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_head', array( $this, 'add_menu_style' ) );
	}

	/**
	 * Tint the development "Mailboxes" top-level menu green so the test-harness menu is obvious, and keep
	 * its submenu permanently expanded — even when another menu is the current one.
	 *
	 * The item is matched by its anchor href. WordPress only expands a top-level menu's submenu inline
	 * while that menu is "current"; otherwise the submenu is a hover-only flyout. Forcing
	 * `display: block; position: relative` reproduces the open/inline state at all times, and
	 * `.wp-submenu-head` (the title row shown only in the flyout) is hidden to match.
	 *
	 * @hooked admin_head
	 */
	public function add_menu_style(): void {

		// The top-level "Mailboxes" link points at the settings page.
		$href = 'admin.php?page=' . Settings::MENU_SLUG;

		// !important so the rules win over the admin colour scheme's current/hover menu states.
		echo '<style id="bh-wp-mailboxes-dev-menu-style">'
			. '#adminmenu li.menu-top:has( > a.menu-top[href="' . esc_attr( $href ) . '"] ),'
			. '#adminmenu li.menu-top:has( > a.menu-top[href="' . esc_attr( $href ) . '"] ) a.menu-top,'
			. '#adminmenu a.menu-top[href="' . esc_attr( $href ) . '"] { background-color: green !important; }'
			// Keep the submenu expanded inline at all times, overriding the hover-only flyout WordPress
			// uses for non-current menus.
			. '#adminmenu li.menu-top:has( > a.menu-top[href="' . esc_attr( $href ) . '"] ) .wp-submenu {'
			. ' display: block !important; position: relative !important; left: auto !important;'
			. ' top: auto !important; margin: 0 !important; min-width: 0 !important; box-shadow: none !important; }'
			. '#adminmenu li.menu-top:has( > a.menu-top[href="' . esc_attr( $href ) . '"] ) .wp-submenu .wp-submenu-head {'
			. ' display: none !important; }'
			. '</style>';
	}

	/**
	 * Add the top-level "Mailboxes" menu pointing at the settings page, with the settings page as the
	 * first submenu, then a link to the emails list for each registered mailbox, then the logs page.
	 *
	 * @hooked admin_menu
	 */
	public function add_menus(): void {

		$parent_slug = Settings::MENU_SLUG;

		// Position 3 places "Mailboxes" between Dashboard (2) and Posts (5); WordPress's core separator (4)
		// sits below it, and the custom separator added below (2.5) sits above it.
		add_menu_page(
			'Mailboxes',
			'Mailboxes',
			'manage_options',
			$parent_slug,
			array( $this->settings_page, 'render' ),
			'dashicons-email',
			3
		);

		// Re-add the top-level slug as the first submenu so it reads "Settings" rather than "Mailboxes".
		add_submenu_page(
			$parent_slug,
			'Settings',
			'Settings',
			'manage_options',
			$parent_slug,
			array( $this->settings_page, 'render' )
		);

		// Add a spacer above "Mailboxes" (between it and Dashboard). WordPress renders any $menu entry
		// whose class list contains `wp-menu-separator` as a spacer; key 2.5 slots it between 2 and 3.
		/**
		 * The WordPress admin menu.
		 *
		 * @var array<string, array<string>> $menu
		 */
		global $menu;
		$menu['2.5'] = array( '', 'read', 'bh-mailboxes-separator-top', '', 'wp-menu-separator' );

		/**
		 * The mailbox instances registered by the library.
		 *
		 * @var BH_WP_Mailboxes[] $mailboxes
		 */
		$mailboxes = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), 'development-plugin' );

		foreach ( $mailboxes as $mailbox ) {
			$mailbox_settings = $mailbox->get_settings();
			add_submenu_page(
				$parent_slug,
				$mailbox_settings->get_emails_cpt_friendly_name(),
				$mailbox_settings->get_emails_cpt_friendly_name(),
				'manage_options',
				'edit.php?post_type=' . $mailbox_settings->get_emails_cpt_underscored_20()
			);
		}

		// Add bottom submenu link to logs page.
		add_submenu_page(
			parent_slug: $parent_slug,
			page_title: 'BH WP Mailboxes Logs',
			menu_title: 'Logs',
			capability: 'manage_options',
			menu_slug: 'admin.php?page=development-plugin-logs'
		);
	}
}

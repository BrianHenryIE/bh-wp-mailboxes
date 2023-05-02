<?php

namespace BrianHenryIE\WP_Mailboxes_Test_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;

class Settings_Page {
	use LoggerAwareTrait;

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * Register the callback to the new page, adding the link in the admin menu.
	 *
	 * @hooked admin_menu
	 */
	public function add_page() {

		$parent_slug = $this->settings->get_cpt_underscored_20();
		$page_title  = 'Mailboxes Settings';
		$menu_title  = 'Settings';
		$capability  = '';
		$menu_slug   = '';
		$function    = array( $this, 'display_page' );
		$position    = null;

		add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function, $position );

	}

	/**
	 * Registered in @see add_page()
	 */
	public function display_page() {

		include wp_normalize_path( __DIR__ . '/partials/bh-wp-mailboxes-test-plugin-admin-display.php' );
	}

}

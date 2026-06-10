<?php
/**
 * Admin settings page for the development plugin.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;

/**
 * Renders the settings admin sub-menu page.
 */
class Settings_Page {
	use LoggerAwareTrait;

	/**
	 * Plugin settings instance.
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * Register the callback to the new page, adding the link in the admin menu.
	 *
	 * @hooked admin_menu
	 */
	public function add_page(): void {

		$parent_slug = $this->settings->get_cpt_underscored_20();
		$page_title  = 'Mailboxes Settings';
		$menu_title  = 'Settings';
		$capability  = '';
		$menu_slug   = '';
		$function    = $this->display_page( ... );
		$position    = null;

		add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function, $position );
	}

	/**
	 * Registered in @see add_page()
	 */
	public function display_page(): void {

		include wp_normalize_path( __DIR__ . '/partials/bh-wp-mailboxes-test-plugin-admin-display.php' );
	}
}

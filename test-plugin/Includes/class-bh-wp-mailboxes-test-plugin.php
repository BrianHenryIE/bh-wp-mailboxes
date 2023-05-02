<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * frontend-facing side of the site and the admin area.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    BH_WP_Mailboxes_Test_Plugin
 * @subpackage BH_WP_Mailboxes_Test_Plugin/includes
 */

namespace BrianHenryIE\WP_Mailboxes_Test_Plugin\Includes;

use BrianHenryIE\WP_Mailboxes_Test_Plugin\Admin\Admin;
use BrianHenryIE\WP_Mailboxes_Test_Plugin\Admin\Ajax;
use BrianHenryIE\WP_Mailboxes_Test_Plugin\Admin\Plugins_Page;
use BrianHenryIE\WP_Mailboxes_Test_Plugin\Frontend\Frontend;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * frontend-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    BH_WP_Mailboxes_Test_Plugin
 * @subpackage BH_WP_Mailboxes_Test_Plugin/includes
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */
class BH_WP_Mailboxes_Test_Plugin {

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the frontend-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct( $mailboxes, $logger ) {

		$this->set_locale();
		$this->define_admin_hooks();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 */
	protected function set_locale(): void {

		$plugin_i18n = new I18n();

		add_action( 'init', array( $plugin_i18n, 'load_plugin_textdomain' ) );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 */
	protected function define_admin_hooks(): void {

		$plugin_admin = new Admin();

		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );

		$ajax = new Ajax();

		$plugins_page = new Plugins_Page();

		$plugin_basename = BH_WP_MAILBOXES_TEST_PLUGIN_BASENAME;
		add_filter( "plugin_action_links_{$plugin_basename}", array( $plugins_page, 'display_plugin_action_links' ), 10, 4 );

	}


}

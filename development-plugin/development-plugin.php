<?php
/**
 * Convenience, demo and test-helper functions for bh-wp-mailboxes.
 *
 * This is a separate WordPress plugin, activated only during development and end-to-end testing.
 * It is never included in the release archive. It adds REST endpoints and authentication shortcuts
 * used to arrange/assert Playwright tests, and wp-env fixes — none of which should ever exist in
 * production. It runs only when the library's test-plugin harness is active.
 *
 * @package brianhenryie/bh-wp-mailboxes
 *
 * @wordpress-plugin
 * Plugin Name:       BH WP Mailboxes Development Plugin
 * Plugin URI:        http://github.com/BrianHenryIE/bh-wp-mailboxes/
 * Description:       Convenience, demo and test helper functions. Activate only in dev/test.
 * Version:           1.0.0
 * Requires PHP:      8.4
 * Author:            BrianHenryIE
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

use Alley_Interactive\Autoloader\Autoloader;
use BrianHenryIE\WP_Logger\Logger;
use BrianHenryIE\WP_Logger\Logger_Settings_Interface;
use BrianHenryIE\WP_Logger\Logger_Settings_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin\Plugins_Page;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_API;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\Mailboxes;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	return;
}

require_once '/var/www/html/wp-content/uploads/bh-wp-mailboxes/vendor/autoload.php';

Autoloader::generate(
	__NAMESPACE__,
	__DIR__,
)->register();

Autoloader::generate(
	'BrianHenryIE\\WP_Mailboxes',
	'/var/www/html/wp-content/uploads/bh-wp-mailboxes/includes/',
)->register();

// This may be outated.
// wp-env fixes (cron / self-referential URLs).
new WP_Env()->register_hooks();

// Authentication shortcuts for e2e arrangement (login-as-user, treat REST callers as admin).
new Authentication()->register_hooks();

// Custom REST endpoints for arranging/asserting e2e tests.
new Mailboxes()->register_hooks();



define( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

new Plugins_Page()->register_hooks();



// I think this is needed because we're mapping the vendor directory to wp-content/plugins/vendor, then libraries/functions
// that use directory path to determine the current plugin, e.g. Private Uploads, cannot wo

// Fix for symlinks in local dev.
add_filter(
	'plugins_url',
	function ( $url, $path, $plugin ) {

		/**
		 * We have mapped the entire project to `localhost:8888/bh-wp-mailboxes/`
		 *
		 * E.g. http://localhost:8888/wp-content/plugins/var/www/html/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
		 * http://localhost:8888/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
		 */
		if(str_contains($url, 'wp-content/plugins/var/www/html/' )){
			$a = 'b';
		}
		// http://localhost:8888/bh-wp-mailboxes/includes/admin/js/bh-wp-mailboxes.js?ver=1.0.0
		if(str_contains($url, '.css' )){
			// http://localhost:8888/wp-content/plugins/var/www/html/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
			// vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
			$a = 'b';
		}
		return str_replace( 'wp-content/plugins/var/www/html/', '', $url );
	},
	10,
	3
);


$logger_settings = new class() implements Logger_Settings_Interface {
	use Logger_Settings_Trait;

	public function get_log_level(): string {
		return 'debug';
	}
	public function get_plugin_slug(): string {
		return 'bh-wp-mailboxes-test-plugin';
	}
	public function get_plugin_basename(): string {
		return defined( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME' ) ? BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME : 'bh-wp-mailboxes-test-plugin/bh-wp-mailboxes-test-plugin.php';
	}
	public function get_plugin_name(): string {
		return 'BH WP Mailboxes Test Plugin';
	}
};

$logger = Logger::instance( $logger_settings );


$mailboxes = array();

$mailboxes[] = new Imap()->get_mailbox_settings();
$mailboxes[] = new Gmail_API()->get_mailbox_settings();


$mailboxes_settings = new class( $mailboxes ) implements BH_WP_Mailboxes_Settings_Interface {
	use BH_WP_Mailboxes_Settings_Defaults_Trait;

	public function __construct(
		protected array $mailboxes = array()
	) {
	}

	public function get_cpt_friendly_name(): string {
		return 'BH WP Mailboxes CPT';
	}

	public function get_configured_mailbox_settings(): array {
		return $this->mailboxes;
	}

	public function get_plugin_slug(): string {
		return 'bh-wp-mailboxes-test-plugin';
	}

};

BH_WP_Mailboxes::instance( $mailboxes_settings, $logger );

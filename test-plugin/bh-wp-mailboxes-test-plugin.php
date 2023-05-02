<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://example.com
 * @since             1.0.0
 * @package           brianhenryie/bh-wp-mailboxes
 *
 * @wordpress-plugin
 * Plugin Name:       BH WP Mailboxes Test Plugin
 * Plugin URI:        http://github.com/BrianHenryIE/bh-wp-mailboxes/
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Author:            BrianHenryIE
 * Author URI:        http://example.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       bh-wp-mailboxes-test-plugin
 * Domain Path:       /languages
 */

namespace BrianHenryIE\WP_Mailboxes_Test_Plugin;

use Alley_Interactive\Autoloader\Autoloader;
use BrianHenryIE\WP_Logger\Logger;
use BrianHenryIE\WP_Logger\Logger_Settings_Trait;
use BrianHenryIE\WP_Logger\WooCommerce_Logger_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes_Test_Plugin\Includes\BH_WP_Mailboxes_Test_Plugin;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use Dotenv\Dotenv;


// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/../vendor/autoload.php';

Autoloader::generate(
	__NAMESPACE__,
	__DIR__,
)->register();

/**
 * Current plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'BH_WP_MAILBOXES_TEST_PLUGIN_VERSION', '1.0.0' );
define( 'BH_WP_MAILBOXES_TEST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function instantiate_bh_wp_mailboxes_test_plugin(): API {

	$logger_settings = new class() implements WooCommerce_Logger_Settings_Interface {
		use Logger_Settings_Trait;

		public function get_log_level(): string {
			return 'debug';
		}
		public function get_plugin_slug(): string {
			return 'bh-wp-mailboxes-test-plugin';
		}
		public function get_plugin_basename(): string {
			return defined( 'BH_WP_MAILBOXES_TEST_PLUGIN_BASENAME' ) ? BH_WP_MAILBOXES_TEST_PLUGIN_BASENAME : 'bh-wp-mailboxes-test-plugin/bh-wp-mailboxes-test-plugin.php';
		}
		public function get_plugin_name(): string {
			return 'BH WP Mailboxes Test Plugin';
		}
	};

	$logger = Logger::instance( $logger_settings );

	$dotenv = Dotenv::createImmutable( __DIR__ . '/../', '.env.secret', true );
	$dotenv->load();

	$imap_mailbox_settings = new class() implements Mailbox_Settings_Interface {
		use Mailbox_Settings_Defaults_Trait;


		public function get_account_unique_friendly_name(): string {
			return 'support@brianhenryie.com';
		}

		public function get_credentials(): Account_Credentials_Interface {
			return new class() implements IMAP_Credentials_Interface {

				public function get_email_imap_server(): string {
					return $_ENV['IMAP_SERVER'];
				}

				public function get_email_account_username(): string {
					return $_ENV['IMAP_USERNAME'];
				}

				public function get_email_account_password(): string {
					return $_ENV['IMAP_PASSWORD'];
				}
			};
		}
	};

	$gmail_mailbox_settings = new class() implements Mailbox_Settings_Interface {
		use Mailbox_Settings_Defaults_Trait;

		public function get_account_unique_friendly_name(): string {
		}

		public function get_credentials(): Account_Credentials_Interface {

			return new class() implements Google_API_Credentials_Interface {

				public function get_project_credentials(): array {
					return json_decode(
						'{
								}',
						true
					);

				}

				public function get_access_token(): ?array {
					return json_decode(
						true
					);
				}
			};
		}

	};

	$mailboxes   = array();
	$mailboxes[] = $imap_mailbox_settings;
	$mailboxes[] = $gmail_mailbox_settings;

	$mailboxes_settings = new class( $mailboxes ) implements BH_WP_Mailboxes_Settings_Interface {
		use BH_WP_Mailboxes_Settings_Defaults_Trait;

		protected array $mailboxes = array();

		public function __construct( array $mailboxes ) {
			$this->mailboxes = $mailboxes;
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

	$mailboxes = BH_WP_Mailboxes::instance( $mailboxes_settings, $logger );

	new BH_WP_Mailboxes_Test_Plugin( $mailboxes, $logger );

	return $mailboxes;
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and frontend-facing site hooks.
 */
$GLOBALS['bh_wp_mailboxes_test_plugin'] = instantiate_bh_wp_mailboxes_test_plugin();

// Fix for symlinks in local dev.
add_filter(
	'plugins_url',
	function( $url, $path, $plugin ) {

		$project_root_dir = dirname( __DIR__ );
		$url              = str_replace( $project_root_dir, '', $url );
		$url              = str_replace( 'wp-content/plugins/vendor', 'vendor', $url );

		return $url;
	},
	10,
	3
);

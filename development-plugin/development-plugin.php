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
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Gmail_API\Google_API_Credentials;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin\Plugins_Page;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\Mailboxes;
use Dotenv\Dotenv;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	return;
}

require_once '/var/www/bh-wp-mailboxes/vendor/autoload.php';

// The library's prefixed Autoloader is already loaded by the test-plugin's vendor autoload.
Autoloader::generate(
	__NAMESPACE__,
	__DIR__,
)->register();

Autoloader::generate(
	'BrianHenryIE\\WP_Mailboxes',
	'/var/www/bh-wp-mailboxes/src/', // TODO: rename to includes.
)->register();

// This may be outated.
// wp-env fixes (cron / self-referential URLs).
( new WP_Env() )->register_hooks();

// Authentication shortcuts for e2e arrangement (login-as-user, treat REST callers as admin).
( new Authentication() )->register_hooks();

// Custom REST endpoints for arranging/asserting e2e tests.
( new Mailboxes() )->register_hooks();



define( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

( new Plugins_Page() )->register_hooks();



// I think this is needed becuase we're mapping the vendor directory to wp-content/plugins/vendor, then libraries/functions
// that use directory path to determine the current plugin, e.g. Private Uploads, cannot wo

// Fix for symlinks in local dev.
add_filter(
	'plugins_url',
	function ( $url, $path, $plugin ) {

		$project_root_dir = dirname( __DIR__ );

		if ( ! str_contains( $path, $project_root_dir ) ) {
			return $url;
		}

		$url = str_replace( $project_root_dir, '', $url );
		$url = str_replace( 'wp-content/plugins/vendor', 'vendor', $url );

		return $url;
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


if ( file_exists( '/var/www/test-credentials/.env.secret', ) ) {
	$dotenv = Dotenv::createImmutable( '/var/www/test-credentials/', '.env.secret', true );
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

	$mailboxes[] = $imap_mailbox_settings;

} else {

	add_action(
		'admin_notices',
		function () {
			// echo NO .env.secret in test-credentials
		}
	);

}

if ( file_exists( '/var/www/test-credentials/credentials.json', ) ) {

	$gmail_mailbox_settings = new class() implements Mailbox_Settings_Interface {
		use Mailbox_Settings_Defaults_Trait;

		public function get_account_unique_friendly_name(): string {
			return 'brianhenryie@gmail.com';
		}

		public function get_credentials(): Account_Credentials_Interface {

			return new Google_API_Credentials( __DIR__ );
		}

	};

	$mailboxes[] = $gmail_mailbox_settings;
}


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

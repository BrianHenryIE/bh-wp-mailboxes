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
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin\Menu;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_API;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_CLI;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_Fixtures_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\Mailboxes;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	return;
}

define( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( file_exists( '/var/www/html/wp-content/uploads/bh-wp-mailboxes/vendor/autoload.php' ) ) {
	require_once '/var/www/html/wp-content/uploads/bh-wp-mailboxes/vendor/autoload.php';
	$includes_dir = '/var/www/html/wp-content/uploads/bh-wp-mailboxes/includes/';
}

$autoloader_path = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $autoloader_path ) ) {
	require_once $autoloader_path;
	$includes_dir = dirname( __DIR__ ) . '/includes/';
}

if ( ! isset( $includes_dir ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error">BH WP Mailboxes – Development plugin – expected path missing. See <code>development-plugin.php</code>.<p>';
		}
	);
	return;
}

Autoloader::generate(
	__NAMESPACE__,
	__DIR__,
)->register();

Autoloader::generate(
	'BrianHenryIE\\WP_Mailboxes',
	$includes_dir,
)->register();

// Authentication shortcuts for e2e arrangement (login-as-user, treat REST callers as admin).
new Authentication()->register_hooks();

// Custom REST endpoints for arranging/asserting e2e tests.
new Mailboxes()->register_hooks();

new Menu()->register_hooks();


/**
 * Partial fix for symlinks.
 *
 * In wp-env: vendor is mapped to wp-content/plugins/vendor.
 * TODO: address the same issue in integration tests.
 *
 * /var/www/html/wp-content/uploads/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/class-admin-assets.php
 * http://localhost:8888/wp-content/plugins/development-plugin/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
 * http://localhost:8888/wp-content/uploads/bh-wp-mailboxes/vendor/brianhenryie/bh-wp-private-uploads/includes/admin/assets/bh-wp-private-uploads-admin.js
 */
$plugins_url_fix = function ( $url, $_path, $_plugin ) {
	$url = str_replace( 'wp-content/plugins/var/www/html/', '', $url );
	$url = str_replace( 'plugins/development-plugin/vendor', 'uploads/bh-wp-mailboxes/vendor', $url );
	$url = str_replace( 'plugins/development-plugin/includes', 'uploads/bh-wp-mailboxes/includes', $url );
	return $url;
};
add_filter( 'plugins_url', $plugins_url_fix, 10, 3 );

$on_plugins_loaded = function () {

	$logger_settings = new class() implements Logger_Settings_Interface {
		use Logger_Settings_Trait;

		/**
		 * Returns the log level.
		 */
		public function get_log_level(): string {
			return 'debug';
		}

		/**
		 * Returns the plugin slug.
		 */
		public function get_plugin_slug(): string {
			return explode( '.', basename( __FILE__ ) )[0];
		}

		/**
		 * Returns the plugin basename.
		 */
		public function get_plugin_basename(): string {
			return (string) defined( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME' )
				? constant( 'BH_WP_MAILBOXES_DEVELOPMENT_PLUGIN_BASENAME' )
				: 'development-plugin/development-plugin.php';
		}

		/**
		 * Returns the plugin display name.
		 */
		public function get_plugin_name(): string {
			return 'BH WP Mailboxes Test Plugin';
		}
	};
	$logger          = Logger::instance( $logger_settings );

	$imap_mailboxes_settings = new class() implements BH_WP_Mailboxes_Settings_Interface {
		use BH_WP_Mailboxes_Settings_Defaults_Trait;

		/**
		 * Returns the plugin slug.
		 */
		public function get_plugin_slug(): string {
			return 'development-plugin';
		}

		/**
		 * Returns the CPT friendly name.
		 */
		public function get_emails_cpt_friendly_name(): string {
			return 'IMAP Email ENV';
		}

		/**
		 * A friendly display name for UI.
		 */
		public function get_email_accounts_cpt_friendly_name(): string {
			return 'IMAP Accounts ENV';
		}
	};
	$imap_mailboxes_api      = BH_WP_Mailboxes::make( $imap_mailboxes_settings, $logger );
	$imap_accounts           = $imap_mailboxes_api->get_email_accounts();

	$imap_env_settings = new Imap()->get_mailbox_settings();

	if ( ! is_null( $imap_env_settings ) && ! isset( $imap_accounts[ $imap_env_settings->get_account_email_address() ] ) ) {
		try {
			$imap_mailboxes_api->add_email_account(
				email_address: $imap_env_settings->get_account_email_address(),
				display_name: $imap_env_settings->get_account_display_friendly_name(),
				provider_type_class: \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection::class,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null,
				delete_local_emails_after_n_days: 1,
			);
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Account already exists; ignore.
		}
	}

	if ( $imap_env_settings ) {
		$imap_credentials = function ( mixed $value, mixed $plugin_slug, mixed $account ) use ( $imap_env_settings ) {
			if ( $account->email_address === $imap_env_settings->get_account_email_address() ) {
				return new Imap()->get_credentials();
			}
			return $value;
		};
		add_filter( 'bh_wp_mailboxes_credentials', $imap_credentials, 10, 3 );
	}

	// Gmail mailbox. Wired only when the OAuth client secret is present. The account itself is created,
	// and its first auth token obtained, via `wp development-plugin gmail connect` (see Gmail_CLI).
	$gmail_api_helper = new Gmail_API();
	if ( $gmail_api_helper->is_client_secret_present() ) {

		$gmail_mailboxes_settings = new class() implements BH_WP_Mailboxes_Settings_Interface {
			use BH_WP_Mailboxes_Settings_Defaults_Trait;

			/**
			 * Returns the plugin slug.
			 */
			public function get_plugin_slug(): string {
				return 'development-plugin';
			}

			/**
			 * Returns the CPT friendly name.
			 */
			public function get_emails_cpt_friendly_name(): string {
				return 'Gmail Email';
			}

			/**
			 * A friendly display name for UI.
			 */
			public function get_email_accounts_cpt_friendly_name(): string {
				return 'Gmail Accounts';
			}
		};

		$gmail_mailboxes_api = BH_WP_Mailboxes::make( $gmail_mailboxes_settings, $logger );

		// Resolve the Gmail account's credentials from /var/www/test-credentials.
		$gmail_credentials = function ( mixed $value, mixed $plugin_slug, mixed $account ) use ( $gmail_api_helper ) {
			if ( $account->email_address === $gmail_api_helper->get_account_email_address() ) {
				return $gmail_api_helper->get_credentials();
			}
			return $value;
		};
		add_filter( 'bh_wp_mailboxes_credentials', $gmail_credentials, 10, 3 );

		$gmail_cli = new Gmail_CLI( $gmail_mailboxes_api, $gmail_mailboxes_settings, $gmail_api_helper, $logger );
		add_action( 'cli_init', $gmail_cli->register_commands( ... ) );
	}

	$fixtures_mailboxes_settings = new class() implements BH_WP_Mailboxes_Settings_Interface {
		use BH_WP_Mailboxes_Settings_Defaults_Trait;

		/**
		 * Returns the plugin slug.
		 */
		public function get_plugin_slug(): string {
			return 'development-plugin';
		}

		/**
		 * Returns the CPT friendly name.
		 */
		public function get_emails_cpt_friendly_name(): string {
			return 'Fixtures Email';
		}

		/**
		 * A friendly display name for UI.
		 */
		public function get_email_accounts_cpt_friendly_name(): string {
			return 'Fixtures Accounts';
		}
	};
	$fixtures_mailboxes_api      = BH_WP_Mailboxes::make( $fixtures_mailboxes_settings, $logger );
	$fixtures_mailboxes_accounts = $fixtures_mailboxes_api->get_email_accounts();

	$fixtures_settings = new class() implements Email_Account_Settings_Interface {
		use Email_Account_Settings_Defaults_Trait;

		/**
		 * The fixtures account email address.
		 */
		public function get_account_email_address(): string {
			return 'fixture@example.com';
		}
	};

	// Ensure the fixtures account exists (its provider is wired up via the filter below).
	if ( ! isset( $fixtures_mailboxes_accounts[ $fixtures_settings->get_account_email_address() ] ) ) {
		try {
			$fixtures_mailboxes_api->add_email_account(
				email_address: $fixtures_settings->get_account_email_address(),
				display_name: $fixtures_settings->get_account_display_friendly_name(),
				provider_type_class: Mock_Mailbox_Fixtures_Connection::class,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null,
				delete_local_emails_after_n_days: 1,
			);
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Account already exists; ignore.
		}
	}
	$email_factory     = new Email_WP_Post_Repository(
		$fixtures_mailboxes_settings->get_emails_cpt_underscored_20(),
		new BH_Email_Factory( $logger ),
		$logger,
	);
	$fixtures_provider = new Mock_Mailbox_Fixtures_Connection( $fixtures_mailboxes_settings, $fixtures_settings, $email_factory );
};
add_action( 'plugins_loaded', $on_plugins_loaded );

/**
 * Fix for mapped directories. I.e. vendor is not under `wp-content/plugins/development-plugins`.
 *
 * @see plugin_basename()
 */
global $wp_plugin_paths;
$plugin_path = '/var/www/html/wp-content/uploads/bh-wp-mailboxes/';
$wp_plugin_paths[ WP_PLUGIN_DIR . '/development-plugin/' ] = $plugin_path;

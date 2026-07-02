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
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin\Menu;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin\Settings;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Fixtures_Account_Settings;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_API;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_CLI;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap_Credentials_Settings;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Mailbox_Settings;
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
	$includes_dir = sprintf( '%s/includes/', dirname( __DIR__ ) );
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

new Mappings()->register_hooks();

// Custom REST endpoints for arranging/asserting e2e tests.
new Mailboxes()->register_hooks();

$development_settings_page = new Settings();
$development_settings_page->register_hooks();
new Menu( $development_settings_page )->register_hooks();


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

	// Example parent-plugin integration: log each newly downloaded email (see Example_Integration).
	new Example_Integration( $logger )->register_hooks();

	$imap_mailboxes_settings = new Mailbox_Settings( 'development-plugin', 'IMAP Email ENV', 'IMAP Accounts ENV' );
	$imap_mailboxes_api      = BH_WP_Mailboxes::make( $imap_mailboxes_settings, $logger );
	$imap_accounts           = $imap_mailboxes_api->get_email_accounts();

	// Load test-credentials/.env.secret into $_ENV when present (side effect), so environment variables
	// take precedence over the settings-page transients in Imap_Credentials_Settings.
	new Imap()->get_mailbox_settings();

	// Credentials come from ENV (preferred) or the dev settings page (transients) — the latter lets the
	// test mailbox be configured in WordPress Playground, where there is no .env.secret file.
	$imap_credentials = new Imap_Credentials_Settings();
	if ( $imap_credentials->is_complete() ) {
		$imap_email = $imap_credentials->get_email_account_username();

		if ( ! isset( $imap_accounts[ $imap_email ] ) ) {
			try {
				$imap_mailboxes_api->add_email_account(
					email_address: $imap_email,
					display_name: $imap_email,
					connection_type_class: \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection::class,
					from_address_regex_filter: null,
					body_identifier_regex_filter: null,
					after_download_remote_email_action: null,
					delete_local_emails_after_n_days: 1,
				);
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Account already exists; ignore.
			}
		}

		$imap_credentials_filter = function ( mixed $value, mixed $plugin_slug, mixed $account ) use ( $imap_email, $imap_credentials ) {
			if ( $account->email_address === $imap_email ) {
				return $imap_credentials;
			}
			return $value;
		};
		add_filter( 'bh_wp_mailboxes_credentials', $imap_credentials_filter, 10, 3 );
	}

	// Gmail mailbox. Wired only when the OAuth client secret is present. The account itself is created,
	// and its first auth token obtained, via `wp development-plugin gmail connect` (see Gmail_CLI).
	$gmail_api_helper = new Gmail_API();
	if ( $gmail_api_helper->is_client_secret_present() ) {

		$gmail_mailboxes_settings = new Mailbox_Settings( 'development-plugin', 'Gmail Email', 'Gmail Accounts' );

		$gmail_mailboxes_api = BH_WP_Mailboxes::make( $gmail_mailboxes_settings, $logger );

		// Resolve the Gmail account's credentials from /var/www/test-credentials.
		$gmail_credentials = function ( mixed $value, string $plugin_slug, BH_Email_Account $account ) use ( $gmail_api_helper ) {
			if ( $account->email_address === $gmail_api_helper->get_account_email_address() ) {
				return $gmail_api_helper->get_credentials();
			}
			return $value;
		};
		add_filter( 'bh_wp_mailboxes_credentials', $gmail_credentials, 10, 3 );

		$gmail_cli = new Gmail_CLI( $gmail_mailboxes_api, $gmail_mailboxes_settings, $gmail_api_helper, $logger );
		add_action( 'cli_init', $gmail_cli->register_commands( ... ) );
	}

	$fixtures_mailboxes_settings = new Mailbox_Settings( 'development-plugin', 'Fixtures Email', 'Fixtures Accounts' );
	$fixtures_mailboxes_api      = BH_WP_Mailboxes::make( $fixtures_mailboxes_settings, $logger );
	$fixtures_mailboxes_accounts = $fixtures_mailboxes_api->get_email_accounts();

	$fixtures_settings = new Fixtures_Account_Settings();

	// Ensure the fixtures account exists (its connection is wired up via the filter below).
	if ( ! isset( $fixtures_mailboxes_accounts[ $fixtures_settings->get_account_email_address() ] ) ) {
		try {
			$fixtures_mailboxes_api->add_email_account(
				email_address: $fixtures_settings->get_account_email_address(),
				display_name: $fixtures_settings->get_account_display_friendly_name(),
				connection_type_class: Mock_Mailbox_Fixtures_Connection::class,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null,
				delete_local_emails_after_n_days: 1,
			);
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Account already exists; ignore.
		}
	}
	$email_factory       = new Email_WP_Post_Repository(
		$fixtures_mailboxes_settings->get_emails_cpt_underscored_20(),
		new BH_Email_Factory( $logger ),
		$logger,
	);
	$fixtures_connection = new Mock_Mailbox_Fixtures_Connection( $fixtures_mailboxes_settings, $fixtures_settings, $email_factory );
};
add_action( 'plugins_loaded', $on_plugins_loaded );

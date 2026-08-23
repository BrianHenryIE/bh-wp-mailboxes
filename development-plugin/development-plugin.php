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
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Dev_Mailboxes;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Fixtures_Account_Settings;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_API;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_Credentials_Options;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_CLI;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap_Credentials_Settings;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Mailbox_Settings;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_Fixtures_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_E2E_Connection;
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

// Self-contained build – the library is installed into the plugin's own vendor directory
// via a Composer path repository. E.g. WordPress Playground in .github/workflows/playground-preview.yml.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	$includes_dir = __DIR__ . '/vendor/brianhenryie/bh-wp-mailboxes/includes/';
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

// The settings for the menu-hidden mailbox used only by the end-to-end tests (see the
// `plugins_loaded` callback below, where the mailbox itself is registered).
$e2e_mailboxes_settings = new Mailbox_Settings( 'development-plugin', 'E2E Email', 'E2E Accounts', 'bh-wp-mailboxes-dev' );

// Custom REST endpoints for arranging/asserting e2e tests.
new Mailboxes( $e2e_mailboxes_settings )->register_hooks();

$development_settings_page = new Settings();
$development_settings_page->register_hooks();
new Menu( $development_settings_page )->register_hooks();


$on_plugins_loaded = function () use ( $e2e_mailboxes_settings ) {

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

	// Load test-credentials/.env.secret into $_ENV when present (side effect), so environment variables
	// take precedence over the settings-page transients in Imap_Credentials_Settings.
	new Imap()->get_mailbox_settings();

	// Two empty demo mailboxes ("Mailbox One" / "Mailbox Two"), configured from the dev settings page:
	// no accounts are pre-seeded — the settings page creates them (from .env.secret, typed-in IMAP
	// credentials, or Gmail credentials) — and REST is enabled per mailbox via a wp_option (the
	// mailbox slug doubles as its REST namespace).
	foreach ( Dev_Mailboxes::get_slugs() as $dev_mailbox_slug ) {
		BH_WP_Mailboxes::make( Dev_Mailboxes::make_settings( $dev_mailbox_slug ), $logger );
	}

	// IMAP credentials for settings-page-created IMAP accounts: ENV (preferred) or the dev settings
	// page (transients) — the latter lets a mailbox be configured in WordPress Playground, where there
	// is no .env.secret file.
	$imap_credentials        = new Imap_Credentials_Settings();
	$imap_credentials_filter = function ( mixed $value, string $plugin_slug, string $emails_post_type, BH_Email_Account $account ) use ( $imap_credentials ) {
		if ( \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection::class === $account->connection_type_class
			&& $imap_credentials->is_complete()
			&& $account->email_address === $imap_credentials->get_email_account_username() ) {
			return $imap_credentials;
		}
		return $value;
	};
	add_filter( 'bh_wp_mailboxes_credentials', $imap_credentials_filter, 10, 4 );

	// Gmail credentials pasted into the settings page, stored as wp_options. Registered before the
	// file-based filter so files, like ENV for IMAP, take precedence on an email-address collision.
	$gmail_pasted_credentials = new Gmail_Credentials_Options();
	$gmail_pasted_filter      = function ( mixed $value, string $plugin_slug, string $emails_post_type, BH_Email_Account $account ) use ( $gmail_pasted_credentials ) {
		if ( Google_API_Credentials_Interface::class === $account->connection_type_class
			&& $gmail_pasted_credentials->is_complete()
			&& $account->email_address === $gmail_pasted_credentials->get_email_address() ) {
			return $gmail_pasted_credentials;
		}
		return $value;
	};
	add_filter( 'bh_wp_mailboxes_credentials', $gmail_pasted_filter, 10, 4 );

	// Gmail credentials from /var/www/test-credentials files. The account itself is created via the
	// settings page or `wp development-plugin gmail connect` (see Gmail_CLI).
	$gmail_api_helper = new Gmail_API();
	if ( $gmail_api_helper->is_client_secret_present() ) {

		$gmail_credentials = function ( mixed $value, string $plugin_slug, string $emails_post_type, BH_Email_Account $account ) use ( $gmail_api_helper ) {
			if ( Google_API_Credentials_Interface::class === $account->connection_type_class
				&& $account->email_address === $gmail_api_helper->get_account_email_address() ) {
				return $gmail_api_helper->get_credentials();
			}
			return $value;
		};
		add_filter( 'bh_wp_mailboxes_credentials', $gmail_credentials, 10, 4 );

		$gmail_cli = new Gmail_CLI( $gmail_api_helper, $logger );
		add_action( 'cli_init', $gmail_cli->register_commands( ... ) );
	}

	// The Fixtures mailbox also enables REST so the dev site advertises two ingress endpoints,
	// exercising the worker's fan-out delivery (every advertised endpoint receives every email).
	$fixtures_mailboxes_settings = new Mailbox_Settings( 'development-plugin', 'Fixtures Email', 'Fixtures Accounts', 'bh-wp-mailboxes-dev' );
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

	// A separate, menu-hidden mailbox used only by the end-to-end tests, so Playwright arranges/asserts in
	// its own `e2e_email` / `e2e_accounts` CPTs and never pollutes the human-facing "Fixtures" demo mailbox.
	// It is registered (so the dev REST /fetch can reach it) but excluded from the admin menu (see Menu);
	// accounts are created on demand by the dev REST endpoints, so no account is pre-seeded here.
	// The E2E mailbox enables REST; Playwright ingress tests target its endpoint specifically
	// (the Fixtures mailbox above advertises the second endpoint).
	BH_WP_Mailboxes::make( $e2e_mailboxes_settings, $logger );
	$e2e_email_repository = new Email_WP_Post_Repository(
		$e2e_mailboxes_settings->get_emails_cpt_underscored_20(),
		new BH_Email_Factory( $logger ),
		$logger,
	);
	$e2e_connection       = new Mock_Mailbox_E2E_Connection( $e2e_mailboxes_settings, $fixtures_settings, $e2e_email_repository );
};
add_action( 'plugins_loaded', $on_plugins_loaded );

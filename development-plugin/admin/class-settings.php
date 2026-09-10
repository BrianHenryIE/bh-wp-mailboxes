<?php
/**
 * Development plugin settings page.
 *
 * Lets a Playground/test user configure the two empty demo mailboxes ("Mailbox One" / "Mailbox Two"):
 * enable REST per mailbox, create an IMAP account from `.env.secret` or from typed-in credentials,
 * create a Gmail account from the test-credentials files or from pasted JSON. Credentials are saved by
 * the library into the WordPress Secrets API.
 * Also runs the fetch cron on demand and inspects the registered custom post types and their statuses.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Modal;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials_Env;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Dev_Mailboxes;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_API;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap;
use Exception;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Renders and handles the development plugin's settings page.
 */
class Settings {

	public const MENU_SLUG              = 'development-plugin-settings';
	public const SAVE_ACTION            = 'bh_wp_mailboxes_dev_save_imap';
	public const RUN_NOW_ACTION         = 'bh_wp_mailboxes_dev_run_now';
	public const SAVE_REST_ACTION       = 'bh_wp_mailboxes_dev_save_rest';
	public const ADD_ENV_IMAP_ACTION    = 'bh_wp_mailboxes_dev_add_env_imap';
	public const USE_GMAIL_FILES_ACTION = 'bh_wp_mailboxes_dev_use_gmail_files';
	public const SAVE_GMAIL_ACTION      = 'bh_wp_mailboxes_dev_save_gmail';

	/**
	 * Which mailbox the typed-in IMAP credentials are configured for ('' when none), and the account's address.
	 */
	public const OPTION_IMAP_MAILBOX = 'bh_wp_mailboxes_dev_imap_mailbox';
	public const OPTION_IMAP_EMAIL   = 'bh_wp_mailboxes_dev_imap_email';

	/**
	 * Which mailbox the pasted Gmail credentials are configured for ('' when none), and the account's address.
	 */
	public const OPTION_GMAIL_MAILBOX = 'bh_wp_mailboxes_dev_gmail_mailbox';
	public const OPTION_GMAIL_EMAIL   = 'bh_wp_mailboxes_dev_gmail_email';

	/**
	 * Success notices, keyed by the `bh_notice` query arg set by the form handlers' redirects.
	 *
	 * @var array<string,string>
	 */
	private const NOTICES = array(
		'saved_account_configured'       => 'IMAP account configured in the mailbox and its credentials saved.',
		'rest_saved'                     => 'REST settings saved. Changes take effect on the next page load.',
		'env_account_configured'         => 'Account from .env.secret configured in the mailbox.',
		'gmail_files_account_configured' => 'Gmail account (file credentials) configured in the mailbox.',
		'gmail_saved_account_configured' => 'Gmail account configured in the mailbox and its credentials saved.',
	);

	/**
	 * Error notices, keyed by the `bh_error` query arg set by the form handlers' redirects.
	 *
	 * @var array<string,string>
	 */
	private const ERRORS = array(
		'mailbox_not_found'     => 'The selected mailbox is not registered.',
		'no_mailbox'            => 'Please choose a mailbox.',
		'env_missing'           => '.env.secret not found, or it does not set IMAP_USERNAME.',
		'imap_incomplete'       => 'The IMAP server, username and password are all required.',
		'gmail_invalid_json'    => 'The pasted Gmail client secret or access token is not valid JSON.',
		'gmail_incomplete'      => 'The Gmail email address and client secret JSON are required.',
		'credentials_not_saved' => 'The credentials could not be saved (is the Secrets API available?). See the log.',
	);

	/**
	 * The emails CPT post statuses registered by the library, label-keyed by slug.
	 *
	 * @var array<string,string>
	 */
	private const EMAIL_STATUSES = array(
		'bh_email_new'       => 'New',
		'bh_email_processed' => 'Processed',
		'bh_email_saved'     => 'Saved',
	);

	/**
	 * The accounts CPT post statuses registered by the library, label-keyed by slug.
	 *
	 * @var array<string,string>
	 */
	private const ACCOUNT_STATUSES = array(
		'bh_email_ac_active'   => 'Active',
		'bh_email_ac_inactive' => 'Inactive',
	);

	/**
	 * The mailbox the reusable add-account modal on this page adds accounts to.
	 */
	public const MODAL_MAILBOX = Dev_Mailboxes::MAILBOX_ONE;

	/**
	 * The library's add/edit IMAP account modal, printed on this page (see {@see render_modal_section()}).
	 *
	 * @var ?Email_Account_Modal
	 */
	private ?Email_Account_Modal $modal = null;

	/**
	 * Register the admin-post handlers for the form actions, and the modal's assets and markup on this screen.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save_imap_credentials' ) );
		add_action( 'admin_post_' . self::RUN_NOW_ACTION, array( $this, 'run_cron_now' ) );
		add_action( 'admin_post_' . self::SAVE_REST_ACTION, array( $this, 'save_rest_settings' ) );
		add_action( 'admin_post_' . self::ADD_ENV_IMAP_ACTION, array( $this, 'add_env_imap_account' ) );
		add_action( 'admin_post_' . self::USE_GMAIL_FILES_ACTION, array( $this, 'use_gmail_file_credentials' ) );
		add_action( 'admin_post_' . self::SAVE_GMAIL_ACTION, array( $this, 'save_gmail_credentials' ) );

		// The README's "Managing accounts from your own screen" recipe: enqueue the modal's script/style and
		// print its markup only on this screen; the button is printed in render_modal_section().
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_modal_assets' ) );
		add_action( 'admin_footer', array( $this, 'print_modal' ) );
	}

	/**
	 * The screen id WordPress assigns this top-level menu page.
	 */
	private function get_screen_id(): string {
		return 'toplevel_page_' . self::MENU_SLUG;
	}

	/**
	 * The modal for {@see self::MODAL_MAILBOX}, built lazily (its settings read a wp_option).
	 */
	private function get_modal(): Email_Account_Modal {
		if ( null === $this->modal ) {
			$this->modal = new Email_Account_Modal( Dev_Mailboxes::make_settings( self::MODAL_MAILBOX ) );
		}
		return $this->modal;
	}

	/**
	 * Enqueue the modal's script and stylesheet on the settings screen only.
	 *
	 * @hooked admin_enqueue_scripts
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_modal_assets( string $hook_suffix ): void {
		if ( $this->get_screen_id() !== $hook_suffix ) {
			return;
		}
		$this->get_modal()->enqueue_assets();
	}

	/**
	 * Print the modal markup (and its nonce) in the footer of the settings screen only.
	 *
	 * @hooked admin_footer
	 */
	public function print_modal(): void {
		$screen = get_current_screen();
		if ( null === $screen || $this->get_screen_id() !== $screen->id ) {
			return;
		}
		$this->get_modal()->print_modal();
	}

	/**
	 * The registered mailbox API instances for this plugin.
	 *
	 * @return API_Interface[]
	 */
	private function get_mailboxes(): array {
		$mailboxes = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), 'development-plugin' );
		return array_values( array_filter( (array) $mailboxes, fn( $m ): bool => $m instanceof API_Interface ) );
	}

	/**
	 * Redirect back to the settings page with a notice query arg.
	 *
	 * @param string $arg   `bh_notice` for success keys, `bh_error` for error keys.
	 * @param string $value The notice key.
	 */
	private function redirect_with_notice( string $arg, string $value ): never {
		// Not menu_page_url(): admin-post.php does not fire `admin_menu`, so that returns '' here.
		wp_safe_redirect( add_query_arg( $arg, $value, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Read and validate a mailbox slug from the submitted form.
	 *
	 * @param string $field The POST field name.
	 *
	 * @return string A valid mailbox slug, or '' when none was chosen.
	 */
	private function get_posted_mailbox( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the calling handler.
		$slug = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		return Dev_Mailboxes::is_valid_slug( $slug ) ? $slug : '';
	}

	/**
	 * The API for a submitted mailbox slug, or redirect with an error.
	 *
	 * @param string $field The POST field holding the mailbox slug.
	 */
	private function get_posted_mailbox_api( string $field ): API_Interface {
		$mailbox_slug = $this->get_posted_mailbox( $field );
		if ( '' === $mailbox_slug ) {
			$this->redirect_with_notice( 'bh_error', 'no_mailbox' );
		}
		$api = Dev_Mailboxes::get_api( $mailbox_slug );
		if ( is_null( $api ) ) {
			$this->redirect_with_notice( 'bh_error', 'mailbox_not_found' );
		}
		return $api;
	}

	/**
	 * Create or update an email account in a mailbox and return it.
	 *
	 * @param API_Interface $api                   The mailbox.
	 * @param string        $email_address         The account's email address.
	 * @param string        $connection_type_class The connection class for fetching.
	 */
	private function configure_account( API_Interface $api, string $email_address, string $connection_type_class ): BH_Email_Account {
		return $api->configure_email_account(
			email_address: $email_address,
			display_name: $email_address,
			connection_type_class: $connection_type_class,
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: 1,
		);
	}

	/**
	 * Save an account's credentials, or redirect with an error.
	 *
	 * @param API_Interface                 $api         The mailbox.
	 * @param BH_Email_Account              $account     The account.
	 * @param Account_Credentials_Interface $credentials The credentials to save.
	 */
	private function save_credentials_or_redirect( API_Interface $api, BH_Email_Account $account, Account_Credentials_Interface $credentials ): void {
		try {
			$api->save_account_credentials( $account, $credentials );
		} catch ( Throwable $throwable ) {
			$this->redirect_with_notice( 'bh_error', 'credentials_not_saved' );
		}
	}

	/**
	 * A posted text field, or the environment variable of the same meaning when it is set (the form
	 * disables the input then, so nothing is posted for it).
	 *
	 * @param string $field   The POST field.
	 * @param string $env_key The environment variable.
	 */
	private function posted_or_env( string $field, string $env_key ): string {
		if ( $this->is_env_set( $env_key ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- loaded from dotenv, used verbatim.
			return is_string( $_ENV[ $env_key ] ) ? $_ENV[ $env_key ] : '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the calling handler.
		return isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	/**
	 * Save the per-mailbox REST-enabled checkboxes to wp_options.
	 */
	public function save_rest_settings(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::SAVE_REST_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is validated against the known mailbox slugs below.
		$enabled_raw = isset( $_POST['rest_enabled'] ) && is_array( $_POST['rest_enabled'] ) ? wp_unslash( $_POST['rest_enabled'] ) : array();

		$enabled = array();
		foreach ( $enabled_raw as $posted_slug ) {
			if ( is_string( $posted_slug ) && Dev_Mailboxes::is_valid_slug( $posted_slug ) ) {
				$enabled[] = $posted_slug;
			}
		}

		foreach ( Dev_Mailboxes::get_slugs() as $slug ) {
			Dev_Mailboxes::set_rest_enabled( $slug, in_array( $slug, $enabled, true ) );
		}

		$this->redirect_with_notice( 'bh_notice', 'rest_saved' );
	}

	/**
	 * Create the IMAP account in the chosen mailbox from the submitted form, and save its credentials
	 * to the Secrets API via the library. A blank password keeps the saved one.
	 */
	public function save_imap_credentials(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::SAVE_ACTION );

		$server     = $this->posted_or_env( 'imap_server', 'IMAP_SERVER' );
		$username   = $this->posted_or_env( 'imap_username', 'IMAP_USERNAME' );
		$encryption = $this->posted_or_env( 'imap_encryption', 'IMAP_ENCRYPTION' );
		if ( ! in_array( $encryption, array( '', 'TLS', 'STARTTLS' ), true ) ) {
			$encryption = '';
		}

		// Passwords are used verbatim — sanitizing would corrupt valid '<', '&', etc. The nonce above
		// validates the request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$password = isset( $_POST['imap_password'] ) && is_string( $_POST['imap_password'] ) ? wp_unslash( $_POST['imap_password'] ) : '';
		if ( $this->is_env_set( 'IMAP_PASSWORD' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- loaded from dotenv, used verbatim.
			$password = is_string( $_ENV['IMAP_PASSWORD'] ) ? $_ENV['IMAP_PASSWORD'] : '';
		}

		$api = $this->get_posted_mailbox_api( 'imap_mailbox' );

		if ( '' === $server || '' === $username ) {
			$this->redirect_with_notice( 'bh_error', 'imap_incomplete' );
		}

		$account = $this->configure_account( $api, $username, ImapEngine_Imap_Email_Connection::class );

		if ( '' === $password ) {
			// Empty submission keeps the saved password.
			$saved    = $api->get_account_credentials( $account );
			$password = $saved instanceof IMAP_Credentials_Interface ? $saved->get_email_account_password() : '';
		}
		if ( '' === $password ) {
			$this->redirect_with_notice( 'bh_error', 'imap_incomplete' );
		}

		$this->save_credentials_or_redirect( $api, $account, new Imap_Credentials( $server, $username, $password, $encryption ) );

		update_option( self::OPTION_IMAP_MAILBOX, $this->get_posted_mailbox( 'imap_mailbox' ) );
		update_option( self::OPTION_IMAP_EMAIL, $username );

		$this->redirect_with_notice( 'bh_notice', 'saved_account_configured' );
	}

	/**
	 * Create an IMAP account from `.env.secret` in the chosen mailbox, saving the env credentials to the Secrets API.
	 */
	public function add_env_imap_account(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::ADD_ENV_IMAP_ACTION );

		// Loads the .env.secret file into $_ENV (side effect) when present.
		$env_settings = new Imap()->get_mailbox_settings();
		if ( is_null( $env_settings ) || '' === $env_settings->get_account_email_address() ) {
			$this->redirect_with_notice( 'bh_error', 'env_missing' );
		}

		$api = $this->get_posted_mailbox_api( 'env_imap_mailbox' );

		$account = $this->configure_account( $api, $env_settings->get_account_email_address(), ImapEngine_Imap_Email_Connection::class );

		$this->save_credentials_or_redirect( $api, $account, new Imap_Credentials_Env() );

		$this->redirect_with_notice( 'bh_notice', 'env_account_configured' );
	}

	/**
	 * Create a Gmail account using the test-credentials files, in the chosen mailbox, saving the file
	 * contents to the Secrets API.
	 */
	public function use_gmail_file_credentials(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::USE_GMAIL_FILES_ACTION );

		$gmail_api = new Gmail_API();
		if ( ! $gmail_api->is_client_secret_present() ) {
			$this->redirect_with_notice( 'bh_error', 'env_missing' );
		}

		$api = $this->get_posted_mailbox_api( 'gmail_files_mailbox' );

		$account = $this->configure_account( $api, $gmail_api->get_account_email_address(), Google_API_Credentials_Interface::class );

		$this->save_credentials_or_redirect( $api, $account, $gmail_api->get_credentials() );

		$this->redirect_with_notice( 'bh_notice', 'gmail_files_account_configured' );
	}

	/**
	 * Create a Gmail account in the chosen mailbox from the pasted client secret and access token JSON,
	 * saving them to the Secrets API. Blank JSON keeps the saved value.
	 */
	public function save_gmail_credentials(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::SAVE_GMAIL_ACTION );

		$email_address = isset( $_POST['gmail_email_address'] ) && is_string( $_POST['gmail_email_address'] ) ? sanitize_email( wp_unslash( $_POST['gmail_email_address'] ) ) : '';

		// The pasted JSON is stored verbatim — sanitizing would corrupt it. It is validated as JSON
		// below and never output. The nonce above validates the request.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$client_secret_json = isset( $_POST['gmail_client_secret_json'] ) && is_string( $_POST['gmail_client_secret_json'] )
			? trim( (string) wp_unslash( $_POST['gmail_client_secret_json'] ) )
			: '';
		$access_token_json  = isset( $_POST['gmail_access_token_json'] ) && is_string( $_POST['gmail_access_token_json'] )
			? trim( (string) wp_unslash( $_POST['gmail_access_token_json'] ) )
			: '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		$api = $this->get_posted_mailbox_api( 'gmail_mailbox' );

		if ( '' === $email_address ) {
			$this->redirect_with_notice( 'bh_error', 'gmail_incomplete' );
		}

		$account = $this->configure_account( $api, $email_address, Google_API_Credentials_Interface::class );
		$saved   = $api->get_account_credentials( $account );
		$saved   = $saved instanceof Google_API_Credentials_Interface ? $saved : null;

		try {
			$client = '' === $client_secret_json
				? $saved?->get_project_credentials()
				: OAuth_Client_Credentials::from_json( $this->decode_json( $client_secret_json ) );
			$token  = '' === $access_token_json
				? $saved?->get_access_token()
				: Access_Token::from_json( $this->decode_json( $access_token_json ) );
		} catch ( Throwable $throwable ) {
			$this->redirect_with_notice( 'bh_error', 'gmail_invalid_json' );
		}

		if ( is_null( $client ) ) {
			$this->redirect_with_notice( 'bh_error', 'gmail_incomplete' );
		}

		$this->save_credentials_or_redirect( $api, $account, new Gmail_Credentials( $client, $token ) );

		update_option( self::OPTION_GMAIL_MAILBOX, $this->get_posted_mailbox( 'gmail_mailbox' ) );
		update_option( self::OPTION_GMAIL_EMAIL, $email_address );

		$this->redirect_with_notice( 'bh_notice', 'gmail_saved_account_configured' );
	}

	/**
	 * Decode pasted JSON to an object, or throw.
	 *
	 * @param string $json The pasted JSON.
	 *
	 * @throws RuntimeException When it is not a JSON object.
	 */
	private function decode_json( string $json ): stdClass {
		$decoded = json_decode( $json );
		if ( ! $decoded instanceof stdClass ) {
			throw new RuntimeException( 'Not a JSON object.' );
		}
		return $decoded;
	}

	/**
	 * The credentials saved for the account last configured from a form on this page, if any.
	 *
	 * @param string $mailbox_option The option holding the mailbox slug.
	 * @param string $email_option   The option holding the account's email address.
	 */
	private function get_configured_credentials( string $mailbox_option, string $email_option ): ?Account_Credentials_Interface {
		$mailbox_slug = get_option( $mailbox_option, '' );
		$email        = get_option( $email_option, '' );
		if ( ! is_string( $mailbox_slug ) || ! is_string( $email ) || '' === $mailbox_slug || '' === $email ) {
			return null;
		}
		$api = Dev_Mailboxes::get_api( $mailbox_slug );
		if ( is_null( $api ) ) {
			return null;
		}
		$account = $api->get_email_accounts()[ $email ] ?? null;

		return is_null( $account ) ? null : $api->get_account_credentials( $account );
	}

	/**
	 * Run the email-fetch for every registered mailbox immediately.
	 */
	public function run_cron_now(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::RUN_NOW_ACTION );

		$count = 0;
		foreach ( $this->get_mailboxes() as $api ) {
			try {
				$count += count( $api->check_email()->get_emails() );
			} catch ( \Throwable $t ) {
				// A test mailbox may be unreachable; don't fatal the request.
				continue;
			}
		}

		// Not menu_page_url(): admin-post.php does not fire `admin_menu`, so that returns '' here.
		wp_safe_redirect( add_query_arg( 'bh_fetched', $count, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render(): void {

		echo '<div class="wrap">';
		echo '<h1>BH WP Mailboxes — Development</h1>';
		// WordPress core's marker for where admin notices are inserted (the library's JS notices use it too).
		echo '<hr class="wp-header-end">';

		$this->render_notices();
		$this->render_mailboxes_section();
		$this->render_env_secret_section();
		$this->render_imap_section();
		$this->render_modal_section();
		$this->render_gmail_section();
		$this->render_cron_section();
		$this->render_cpt_section();

		echo '</div>';
	}

	/**
	 * Render any success/error notices after a redirect.
	 */
	private function render_notices(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects.
		$notice_key = isset( $_GET['bh_notice'] ) && is_string( $_GET['bh_notice'] ) ? sanitize_key( wp_unslash( $_GET['bh_notice'] ) ) : '';
		if ( isset( self::NOTICES[ $notice_key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::NOTICES[ $notice_key ] ) . '</p></div>';
		}
		$error_key = isset( $_GET['bh_error'] ) && is_string( $_GET['bh_error'] ) ? sanitize_key( wp_unslash( $_GET['bh_error'] ) ) : '';
		if ( isset( self::ERRORS[ $error_key ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( self::ERRORS[ $error_key ] ) . '</p></div>';
		}
		if ( isset( $_GET['bh_fetched'] ) && is_numeric( $_GET['bh_fetched'] ) ) {
			$fetched = absint( $_GET['bh_fetched'] );
			echo '<div class="notice notice-success is-dismissible"><p>Fetched ' . esc_html( (string) $fetched ) . ' new email(s).</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render the two demo mailboxes with their per-mailbox REST-enabled checkboxes.
	 */
	private function render_mailboxes_section(): void {

		echo '<h2>Mailboxes</h2>';
		echo '<p>Two empty mailboxes, configured from this page. Enabling REST advertises the mailbox\'s ingress endpoint under its own namespace.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_REST_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_REST_ACTION );

		echo '<table class="widefat striped" style="max-width:680px"><thead><tr>';
		echo '<th>Mailbox</th><th>Emails CPT</th><th>REST namespace</th><th>REST enabled</th>';
		echo '</tr></thead><tbody>';

		foreach ( Dev_Mailboxes::get_names() as $slug => $names ) {
			$settings = Dev_Mailboxes::make_settings( $slug );
			echo '<tr>';
			echo '<td>' . esc_html( $names['emails'] ) . '</td>';
			echo '<td><code>' . esc_html( $settings->get_emails_cpt_underscored_20() ) . '</code></td>';
			echo '<td><code>' . esc_html( $slug ) . '</code></td>';
			echo '<td><input type="checkbox" id="rest_enabled_' . esc_attr( $slug ) . '" name="rest_enabled[]" value="' . esc_attr( $slug ) . '"' . checked( Dev_Mailboxes::is_rest_enabled( $slug ), true, false ) . ' /></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		submit_button( 'Save REST settings' );
		echo '</form>';
	}

	/**
	 * Render the `.env.secret` detection and the add-account-to-mailbox button.
	 */
	private function render_env_secret_section(): void {

		echo '<h2>.env.secret IMAP account</h2>';

		$imap = new Imap();
		if ( ! $imap->is_credentials_present() ) {
			echo '<p>No <code>.env.secret</code> file found in <code>test-credentials</code>.</p>';
			return;
		}

		$env_settings  = $imap->get_mailbox_settings();
		$email_address = is_null( $env_settings ) ? '' : $env_settings->get_account_email_address();

		echo '<p>Found <code>.env.secret</code>';
		if ( '' !== $email_address ) {
			echo ' for <code>' . esc_html( $email_address ) . '</code>';
		}
		echo '. Add its IMAP account to a mailbox:</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD_ENV_IMAP_ACTION ) . '" />';
		wp_nonce_field( self::ADD_ENV_IMAP_ACTION );
		$this->render_mailbox_select( 'env_imap_mailbox', Dev_Mailboxes::MAILBOX_ONE, false );
		echo ' ';
		submit_button( 'Add .env.secret account', 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render the IMAP credentials form with its configured-for-mailbox dropdown, pre-filled from the
	 * environment or the saved credentials (never the password).
	 */
	private function render_imap_section(): void {

		$saved = $this->get_configured_credentials( self::OPTION_IMAP_MAILBOX, self::OPTION_IMAP_EMAIL );
		$saved = $saved instanceof IMAP_Credentials_Interface ? $saved : null;

		echo '<h2>IMAP setup</h2>';
		echo '<p>Type IMAP credentials for a mailbox account (e.g. in WordPress Playground). They are saved, encrypted, in the WordPress Secrets API via <code>API::save_account_credentials()</code>. Environment variables, when present, take precedence over these values.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_text_field( 'imap_server', 'Server', 'IMAP_SERVER', $saved?->get_email_imap_server() ?? '' );
		$this->render_text_field( 'imap_username', 'Username', 'IMAP_USERNAME', $saved?->get_email_account_username() ?? '' );
		$this->render_password_field( ! is_null( $saved ) );
		$this->render_encryption_field( $saved?->get_encryption() ?? '' );

		$imap_mailbox = get_option( self::OPTION_IMAP_MAILBOX, '' );
		echo '<tr><th scope="row"><label for="imap_mailbox">Mailbox</label></th><td>';
		$this->render_mailbox_select( 'imap_mailbox', is_string( $imap_mailbox ) ? $imap_mailbox : '', true );
		echo '<p class="description">Which mailbox this account is configured for. Saving creates the account and saves its credentials.</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( 'Save IMAP credentials' );
		echo '</form>';
	}

	/**
	 * Render the library's reusable add-account modal section: the "Add account" button that opens
	 * `Email_Account_Modal` outside the emails list screen, as a consumer would on its own settings page.
	 */
	private function render_modal_section(): void {

		$mailbox_name = Dev_Mailboxes::get_names()[ self::MODAL_MAILBOX ]['emails'];
		$list_url     = admin_url( 'edit.php?post_type=' . Dev_Mailboxes::make_settings( self::MODAL_MAILBOX )->get_emails_cpt_underscored_20() );

		echo '<div class="bh-dev-modal-section">';
		echo '<h2>Add IMAP account (modal)</h2>';
		echo '<p>The library\'s add/edit account modal, reused outside the emails list screen (the README\'s "Managing accounts from your own screen"). ';
		echo 'The modal\'s AJAX actions are bound to one mailbox, so this one adds accounts to <strong>' . esc_html( $mailbox_name ) . '</strong>; ';
		echo 'the account then appears in <a href="' . esc_url( $list_url ) . '">its emails list\'s accounts table</a>, where it can be edited, disabled and deleted. ';
		echo 'The credentials are saved, encrypted, in the WordPress Secrets API.</p>';
		$this->get_modal()->print_add_button();
		echo '</div>';
	}

	/**
	 * Render the Gmail section: file-based credentials offer and the pasted-credentials form.
	 */
	private function render_gmail_section(): void {

		echo '<h2>Gmail</h2>';

		$gmail_api = new Gmail_API();

		echo '<h3>Credentials from files</h3>';
		if ( $gmail_api->is_client_secret_present() ) {
			echo '<p>Found the OAuth client secret in <code>' . esc_html( Gmail_API::CREDENTIALS_DIRECTORY ) . '</code>';
			echo $gmail_api->is_credentials_present() ? ' (access token present).' : ' (no access token yet — authorize via <code>wp development-plugin gmail connect</code>).';
			echo ' Use these for <code>' . esc_html( $gmail_api->get_account_email_address() ) . '</code> in a mailbox (they are copied into the Secrets API):</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::USE_GMAIL_FILES_ACTION ) . '" />';
			wp_nonce_field( self::USE_GMAIL_FILES_ACTION );
			$this->render_mailbox_select( 'gmail_files_mailbox', Dev_Mailboxes::MAILBOX_ONE, false );
			echo ' ';
			submit_button( 'Use file credentials', 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p>No Gmail client secret file found in <code>' . esc_html( Gmail_API::CREDENTIALS_DIRECTORY ) . '</code>.</p>';
		}

		$saved       = $this->get_configured_credentials( self::OPTION_GMAIL_MAILBOX, self::OPTION_GMAIL_EMAIL );
		$saved       = $saved instanceof Google_API_Credentials_Interface ? $saved : null;
		$gmail_email = get_option( self::OPTION_GMAIL_EMAIL, '' );

		echo '<h3>Pasted credentials</h3>';
		echo '<p>Paste the OAuth client secret JSON and access token JSON; they are saved, encrypted, in the WordPress Secrets API and are not displayed again.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_GMAIL_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_GMAIL_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gmail_email_address">Email address</label></th><td>';
		echo '<input type="email" class="regular-text" id="gmail_email_address" name="gmail_email_address" value="' . esc_attr( is_string( $gmail_email ) ? $gmail_email : '' ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gmail_client_secret_json">Client secret JSON</label></th><td>';
		echo '<textarea class="large-text code" rows="6" id="gmail_client_secret_json" name="gmail_client_secret_json" placeholder="' . esc_attr( is_null( $saved ) ? '' : '(saved; leave blank to keep)' ) . '"></textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gmail_access_token_json">Access token JSON</label></th><td>';
		echo '<textarea class="large-text code" rows="6" id="gmail_access_token_json" name="gmail_access_token_json" placeholder="' . esc_attr( is_null( $saved?->get_access_token() ) ? '' : '(saved; leave blank to keep)' ) . '"></textarea>';
		echo '</td></tr>';

		$gmail_mailbox = get_option( self::OPTION_GMAIL_MAILBOX, '' );
		echo '<tr><th scope="row"><label for="gmail_mailbox">Mailbox</label></th><td>';
		$this->render_mailbox_select( 'gmail_mailbox', is_string( $gmail_mailbox ) ? $gmail_mailbox : '', true );
		echo '<p class="description">Which mailbox this account is configured for. Saving creates the account and saves its credentials.</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( 'Save Gmail credentials' );
		echo '</form>';
	}

	/**
	 * Render a select of the two demo mailboxes.
	 *
	 * @param string $name         The select's name and id.
	 * @param string $selected     The currently selected mailbox slug.
	 * @param bool   $include_none Whether to include an empty "None" option.
	 */
	private function render_mailbox_select( string $name, string $selected, bool $include_none ): void {

		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		if ( $include_none ) {
			echo '<option value=""' . selected( $selected, '', false ) . '>None</option>';
		}
		foreach ( Dev_Mailboxes::get_names() as $slug => $names ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $selected, $slug, false ) . '>' . esc_html( $names['emails'] ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Render a labelled text input row, locked when the matching environment variable is set.
	 *
	 * @param string $name    The input name.
	 * @param string $label   The row label.
	 * @param string $env_key The environment variable that overrides this field.
	 * @param string $value   The current resolved value.
	 */
	private function render_text_field( string $name, string $label, string $env_key, string $value ): void {

		$from_env = $this->is_env_set( $env_key );

		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $from_env ? ' disabled' : '' ) . ' />';
		if ( $from_env ) {
			echo '<p class="description">Set via environment variable <code>' . esc_html( $env_key ) . '</code>.</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render the password row (kept blank; only updated when a value is entered).
	 *
	 * @param bool $has_value Whether a password is already saved.
	 */
	private function render_password_field( bool $has_value ): void {
		$from_env = $this->is_env_set( 'IMAP_PASSWORD' );
		echo '<tr><th scope="row"><label for="imap_password">Password</label></th><td>';
		echo '<input type="password" class="regular-text" id="imap_password" name="imap_password" value="" autocomplete="new-password" placeholder="' . esc_attr( $has_value ? '(unchanged)' : '' ) . '"' . ( $from_env ? ' disabled' : '' ) . ' />';
		if ( $from_env ) {
			echo '<p class="description">Set via environment variable <code>IMAP_PASSWORD</code>.</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render the encryption select row.
	 *
	 * @param string $value The current encryption value.
	 */
	private function render_encryption_field( string $value ): void {

		$from_env = $this->is_env_set( 'IMAP_ENCRYPTION' );

		echo '<tr><th scope="row"><label for="imap_encryption">Encryption</label></th><td>';
		echo '<select id="imap_encryption" name="imap_encryption"' . ( $from_env ? ' disabled' : '' ) . '>';
		foreach ( array(
			''         => 'None',
			'TLS'      => 'TLS',
			'STARTTLS' => 'STARTTLS',
		) as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( $option_value ) . '"' . selected( $value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>';
		if ( $from_env ) {
			echo '<p class="description">Set via environment variable <code>IMAP_ENCRYPTION</code>.</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render the cron status and the "run now" button.
	 */
	private function render_cron_section(): void {

		echo '<h2>Email fetch cron</h2>';

		$mailboxes = $this->get_mailboxes();
		if ( array() === $mailboxes ) {
			echo '<p>No mailboxes are registered.</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:680px"><thead><tr>';
		echo '<th>Mailbox</th><th>Cron hook</th><th>Scheduled</th><th>Next run</th>';
		echo '</tr></thead><tbody>';

		foreach ( $mailboxes as $api ) {
			$emails_cpt = $api->get_settings()->get_emails_cpt_underscored_20();
			$hook       = sanitize_key( $emails_cpt ) . '_fetch_emails_job';
			$next       = wp_next_scheduled( $hook );

			echo '<tr>';
			echo '<td>' . esc_html( $api->get_settings()->get_emails_cpt_friendly_name() ) . '</td>';
			echo '<td><code>' . esc_html( $hook ) . '</code></td>';
			echo '<td>' . ( false === $next ? 'No' : 'Yes' ) . '</td>';
			echo '<td>' . esc_html( false === $next ? '—' : human_time_diff( time(), $next ) . ' (' . gmdate( 'Y-m-d H:i:s', $next ) . ' UTC)' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_NOW_ACTION ) . '" />';
		wp_nonce_field( self::RUN_NOW_ACTION );
		submit_button( 'Fetch emails now', 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render the registered CPTs and their possible statuses.
	 */
	private function render_cpt_section(): void {

		echo '<h2>Registered post types</h2>';

		$mailboxes = $this->get_mailboxes();
		if ( array() === $mailboxes ) {
			echo '<p>No mailboxes are registered.</p>';
			return;
		}

		foreach ( $mailboxes as $api ) {
			$settings = $api->get_settings();

			echo '<h3>' . esc_html( $settings->get_emails_cpt_friendly_name() ) . '</h3>';
			$this->render_cpt_statuses( $settings->get_emails_cpt_underscored_20(), self::EMAIL_STATUSES );

			echo '<h3>' . esc_html( $settings->get_email_accounts_cpt_friendly_name() ) . '</h3>';
			$this->render_cpt_statuses( $settings->get_email_accounts_cpt_underscored_20(), self::ACCOUNT_STATUSES );
		}
	}

	/**
	 * Render a CPT's statuses with their current post counts.
	 *
	 * @param string               $post_type The custom post type key.
	 * @param array<string,string> $statuses  The status slugs mapped to fallback labels.
	 */
	private function render_cpt_statuses( string $post_type, array $statuses ): void {

		$counts = (array) wp_count_posts( $post_type );

		echo '<p>Post type key: <code>' . esc_html( $post_type ) . '</code></p>';
		echo '<table class="widefat striped" style="max-width:480px"><thead><tr>';
		echo '<th>Status</th><th>Slug</th><th>Count</th></tr></thead><tbody>';

		foreach ( $statuses as $slug => $fallback_label ) {
			$status_object = get_post_status_object( $slug );
			$label         = $status_object instanceof stdClass && isset( $status_object->label ) && is_string( $status_object->label ) ? $status_object->label : $fallback_label;
			$count         = isset( $counts[ $slug ] ) && is_numeric( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td><code>' . esc_html( $slug ) . '</code></td>';
			echo '<td>' . esc_html( (string) $count ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Whether the given environment variable is set and non-empty.
	 *
	 * @param string $env_key The environment variable name.
	 */
	private function is_env_set( string $env_key ): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence check only.
		return isset( $_ENV[ $env_key ] ) && '' !== $_ENV[ $env_key ];
	}
}

<?php
/**
 * Development plugin settings page.
 *
 * Lets a Playground/test user configure the two empty demo mailboxes ("Mailbox One" / "Mailbox Two"):
 * enable REST per mailbox, create an IMAP account from `.env.secret` or from typed-in credentials,
 * create a Gmail account from the test-credentials files or from pasted JSON stored in wp_options.
 * Also runs the fetch cron on demand and inspects the registered custom post types and their statuses.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Dev_Mailboxes;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_API;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_Credentials_Options;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap_Credentials_Settings;
use Exception;
use stdClass;

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
	 * Which mailbox the typed-in IMAP credentials are configured for ('' when none).
	 */
	public const OPTION_IMAP_MAILBOX = 'bh_wp_mailboxes_dev_imap_mailbox';

	/**
	 * Which mailbox the pasted Gmail credentials are configured for ('' when none).
	 */
	public const OPTION_GMAIL_MAILBOX = 'bh_wp_mailboxes_dev_gmail_mailbox';

	/**
	 * Success notices, keyed by the `bh_notice` query arg set by the form handlers' redirects.
	 *
	 * @var array<string,string>
	 */
	private const NOTICES = array(
		'saved'                          => 'IMAP credentials saved.',
		'saved_account_configured'       => 'IMAP credentials saved and account configured in the mailbox.',
		'rest_saved'                     => 'REST settings saved. Changes take effect on the next page load.',
		'env_account_configured'         => 'Account from .env.secret configured in the mailbox.',
		'gmail_files_account_configured' => 'Gmail account (file credentials) configured in the mailbox.',
		'gmail_saved'                    => 'Gmail credentials saved.',
		'gmail_saved_account_configured' => 'Gmail credentials saved and account configured in the mailbox.',
	);

	/**
	 * Error notices, keyed by the `bh_error` query arg set by the form handlers' redirects.
	 *
	 * @var array<string,string>
	 */
	private const ERRORS = array(
		'mailbox_not_found'  => 'The selected mailbox is not registered.',
		'no_mailbox'         => 'Please choose a mailbox.',
		'env_missing'        => '.env.secret not found, or it does not set IMAP_USERNAME.',
		'gmail_invalid_json' => 'The pasted Gmail client secret is not valid JSON.',
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
	 * Register the admin-post handlers for the form actions.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save_imap_credentials' ) );
		add_action( 'admin_post_' . self::RUN_NOW_ACTION, array( $this, 'run_cron_now' ) );
		add_action( 'admin_post_' . self::SAVE_REST_ACTION, array( $this, 'save_rest_settings' ) );
		add_action( 'admin_post_' . self::ADD_ENV_IMAP_ACTION, array( $this, 'add_env_imap_account' ) );
		add_action( 'admin_post_' . self::USE_GMAIL_FILES_ACTION, array( $this, 'use_gmail_file_credentials' ) );
		add_action( 'admin_post_' . self::SAVE_GMAIL_ACTION, array( $this, 'save_gmail_credentials' ) );
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
	 * Create or update an email account in the given mailbox.
	 *
	 * @param string $mailbox_slug          The mailbox to configure the account in.
	 * @param string $email_address         The account's email address.
	 * @param string $connection_type_class The connection class for fetching.
	 *
	 * @return string Notice key: account_configured|mailbox_not_found.
	 */
	private function configure_account_in_mailbox( string $mailbox_slug, string $email_address, string $connection_type_class ): string {

		$api = Dev_Mailboxes::get_api( $mailbox_slug );
		if ( is_null( $api ) ) {
			return 'mailbox_not_found';
		}

		$api->configure_email_account(
			email_address: $email_address,
			display_name: $email_address,
			connection_type_class: $connection_type_class,
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: 1,
		);

		return 'account_configured';
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
	 * Save the submitted IMAP credentials to transients, and create the account in the chosen mailbox.
	 */
	public function save_imap_credentials(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::SAVE_ACTION );

		$server     = isset( $_POST['imap_server'] ) && is_string( $_POST['imap_server'] ) ? sanitize_text_field( wp_unslash( $_POST['imap_server'] ) ) : '';
		$username   = isset( $_POST['imap_username'] ) && is_string( $_POST['imap_username'] ) ? sanitize_text_field( wp_unslash( $_POST['imap_username'] ) ) : '';
		$encryption = isset( $_POST['imap_encryption'] ) && is_string( $_POST['imap_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['imap_encryption'] ) ) : '';
		if ( ! in_array( $encryption, array( '', 'TLS', 'STARTTLS' ), true ) ) {
			$encryption = '';
		}

		// Passwords are used verbatim — sanitizing would corrupt valid '<', '&', etc. The nonce above
		// validates the request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$password = isset( $_POST['imap_password'] ) && is_string( $_POST['imap_password'] ) ? wp_unslash( $_POST['imap_password'] ) : '';
		if ( '' === $password ) {
			// Empty submission leaves the stored password unchanged.
			$existing = get_transient( Imap_Credentials_Settings::TRANSIENT_PASSWORD );
			$password = is_string( $existing ) ? $existing : '';
		}

		Imap_Credentials_Settings::save( $server, $username, $password, $encryption );

		$mailbox_slug = $this->get_posted_mailbox( 'imap_mailbox' );
		update_option( self::OPTION_IMAP_MAILBOX, $mailbox_slug );

		$credentials = new Imap_Credentials_Settings();
		if ( '' === $mailbox_slug || ! $credentials->is_complete() ) {
			$this->redirect_with_notice( 'bh_notice', 'saved' );
		}

		$result = $this->configure_account_in_mailbox(
			$mailbox_slug,
			$credentials->get_email_account_username(),
			ImapEngine_Imap_Email_Connection::class
		);

		match ( $result ) {
			'account_configured' => $this->redirect_with_notice( 'bh_notice', 'saved_account_configured' ),
			default              => $this->redirect_with_notice( 'bh_error', 'mailbox_not_found' ),
		};
	}

	/**
	 * Create an IMAP account from `.env.secret` in the chosen mailbox.
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

		$mailbox_slug = $this->get_posted_mailbox( 'env_imap_mailbox' );
		if ( '' === $mailbox_slug ) {
			$this->redirect_with_notice( 'bh_error', 'no_mailbox' );
		}

		$result = $this->configure_account_in_mailbox(
			$mailbox_slug,
			$env_settings->get_account_email_address(),
			ImapEngine_Imap_Email_Connection::class
		);

		match ( $result ) {
			'account_configured' => $this->redirect_with_notice( 'bh_notice', 'env_account_configured' ),
			default              => $this->redirect_with_notice( 'bh_error', 'mailbox_not_found' ),
		};
	}

	/**
	 * Create a Gmail account using the test-credentials files, in the chosen mailbox.
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

		$mailbox_slug = $this->get_posted_mailbox( 'gmail_files_mailbox' );
		if ( '' === $mailbox_slug ) {
			$this->redirect_with_notice( 'bh_error', 'no_mailbox' );
		}

		$result = $this->configure_account_in_mailbox(
			$mailbox_slug,
			$gmail_api->get_account_email_address(),
			Google_API_Credentials_Interface::class
		);

		match ( $result ) {
			'account_configured' => $this->redirect_with_notice( 'bh_notice', 'gmail_files_account_configured' ),
			default              => $this->redirect_with_notice( 'bh_error', 'mailbox_not_found' ),
		};
	}

	/**
	 * Save the pasted Gmail credentials to wp_options, and create the account in the chosen mailbox.
	 */
	public function save_gmail_credentials(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::SAVE_GMAIL_ACTION );

		$email_address = isset( $_POST['gmail_email_address'] ) && is_string( $_POST['gmail_email_address'] ) ? sanitize_email( wp_unslash( $_POST['gmail_email_address'] ) ) : '';

		// The pasted JSON is stored verbatim — sanitizing would corrupt it. It is validated as JSON
		// below and only ever output escaped. The nonce above validates the request.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$client_secret_json = isset( $_POST['gmail_client_secret_json'] ) && is_string( $_POST['gmail_client_secret_json'] )
			? trim( (string) wp_unslash( $_POST['gmail_client_secret_json'] ) )
			: '';
		$access_token_json  = isset( $_POST['gmail_access_token_json'] ) && is_string( $_POST['gmail_access_token_json'] )
			? trim( (string) wp_unslash( $_POST['gmail_access_token_json'] ) )
			: '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		if ( '' !== $client_secret_json && ! ( json_decode( $client_secret_json ) instanceof stdClass ) ) {
			$this->redirect_with_notice( 'bh_error', 'gmail_invalid_json' );
		}
		if ( '' !== $access_token_json && ! ( json_decode( $access_token_json ) instanceof stdClass ) ) {
			$this->redirect_with_notice( 'bh_error', 'gmail_invalid_json' );
		}

		Gmail_Credentials_Options::save( $email_address, $client_secret_json, $access_token_json );

		$mailbox_slug = $this->get_posted_mailbox( 'gmail_mailbox' );
		update_option( self::OPTION_GMAIL_MAILBOX, $mailbox_slug );

		$credentials = new Gmail_Credentials_Options();
		if ( '' === $mailbox_slug || ! $credentials->is_complete() ) {
			$this->redirect_with_notice( 'bh_notice', 'gmail_saved' );
		}

		$result = $this->configure_account_in_mailbox(
			$mailbox_slug,
			$credentials->get_email_address(),
			Google_API_Credentials_Interface::class
		);

		match ( $result ) {
			'account_configured' => $this->redirect_with_notice( 'bh_notice', 'gmail_saved_account_configured' ),
			default              => $this->redirect_with_notice( 'bh_error', 'mailbox_not_found' ),
		};
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

		$this->render_notices();
		$this->render_mailboxes_section();
		$this->render_env_secret_section();
		$this->render_imap_section();
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
	 * Render the IMAP credentials form with its configured-for-mailbox dropdown.
	 */
	private function render_imap_section(): void {

		$credentials = new Imap_Credentials_Settings();

		echo '<h2>IMAP setup</h2>';
		echo '<p>Type IMAP credentials for a mailbox account (e.g. in WordPress Playground). Environment variables, when present, take precedence over these values.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_text_field( 'imap_server', 'Server', 'IMAP_SERVER', $credentials->get_email_imap_server() );
		$this->render_text_field( 'imap_username', 'Username', 'IMAP_USERNAME', $credentials->get_email_account_username() );
		$this->render_password_field( $credentials );
		$this->render_encryption_field( $credentials->get_encryption() );

		$imap_mailbox = get_option( self::OPTION_IMAP_MAILBOX, '' );
		echo '<tr><th scope="row"><label for="imap_mailbox">Mailbox</label></th><td>';
		$this->render_mailbox_select( 'imap_mailbox', is_string( $imap_mailbox ) ? $imap_mailbox : '', true );
		echo '<p class="description">Which mailbox this account is configured for. Saving with complete credentials creates the account.</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( 'Save IMAP credentials' );
		echo '</form>';
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
			echo ' Use these for <code>' . esc_html( $gmail_api->get_account_email_address() ) . '</code> in a mailbox:</p>';

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

		$pasted = new Gmail_Credentials_Options();

		echo '<h3>Pasted credentials</h3>';
		echo '<p>Paste the OAuth client secret JSON and access token JSON; they are stored as wp_options.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_GMAIL_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_GMAIL_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gmail_email_address">Email address</label></th><td>';
		echo '<input type="email" class="regular-text" id="gmail_email_address" name="gmail_email_address" value="' . esc_attr( $pasted->get_email_address() ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gmail_client_secret_json">Client secret JSON</label></th><td>';
		echo '<textarea class="large-text code" rows="6" id="gmail_client_secret_json" name="gmail_client_secret_json">' . esc_textarea( $pasted->get_client_secret_json() ) . '</textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gmail_access_token_json">Access token JSON</label></th><td>';
		echo '<textarea class="large-text code" rows="6" id="gmail_access_token_json" name="gmail_access_token_json">' . esc_textarea( $pasted->get_access_token_json() ) . '</textarea>';
		echo '</td></tr>';

		$gmail_mailbox = get_option( self::OPTION_GMAIL_MAILBOX, '' );
		echo '<tr><th scope="row"><label for="gmail_mailbox">Mailbox</label></th><td>';
		$this->render_mailbox_select( 'gmail_mailbox', is_string( $gmail_mailbox ) ? $gmail_mailbox : '', true );
		echo '<p class="description">Which mailbox this account is configured for. Saving with a valid client secret creates the account.</p>';
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
	 * @param Imap_Credentials_Settings $credentials The current credentials.
	 */
	private function render_password_field( Imap_Credentials_Settings $credentials ): void {

		$from_env  = $this->is_env_set( 'IMAP_PASSWORD' );
		$has_value = '' !== $credentials->get_email_account_password();

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

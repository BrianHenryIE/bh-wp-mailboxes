<?php
/**
 * Development plugin settings page.
 *
 * Lets a Playground/test user configure the IMAP test mailbox, run the fetch cron on demand, and
 * inspect the registered custom post types and their statuses.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Imap_Credentials_Settings;

/**
 * Renders and handles the development plugin's settings page.
 */
class Settings {

	public const MENU_SLUG      = 'development-plugin-settings';
	public const SAVE_ACTION    = 'bh_wp_mailboxes_dev_save_imap';
	public const RUN_NOW_ACTION = 'bh_wp_mailboxes_dev_run_now';

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
	 * Save the submitted IMAP credentials to transients.
	 */
	public function save_imap_credentials(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::SAVE_ACTION );

		$server     = isset( $_POST['imap_server'] ) ? sanitize_text_field( wp_unslash( $_POST['imap_server'] ) ) : '';
		$username   = isset( $_POST['imap_username'] ) ? sanitize_text_field( wp_unslash( $_POST['imap_username'] ) ) : '';
		$encryption = isset( $_POST['imap_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['imap_encryption'] ) ) : '';
		if ( ! in_array( $encryption, array( '', 'TLS', 'STARTTLS' ), true ) ) {
			$encryption = '';
		}

		// Passwords are used verbatim — sanitizing would corrupt valid '<', '&', etc. The nonce above
		// validates the request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$password = isset( $_POST['imap_password'] ) ? (string) wp_unslash( $_POST['imap_password'] ) : '';
		if ( '' === $password ) {
			// Empty submission leaves the stored password unchanged.
			$existing = get_transient( Imap_Credentials_Settings::TRANSIENT_PASSWORD );
			$password = is_string( $existing ) ? $existing : '';
		}

		Imap_Credentials_Settings::save( $server, $username, $password, $encryption );

		wp_safe_redirect( add_query_arg( 'bh_notice', 'saved', menu_page_url( self::MENU_SLUG, false ) ) );
		exit;
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

		wp_safe_redirect( add_query_arg( 'bh_fetched', $count, menu_page_url( self::MENU_SLUG, false ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render(): void {

		echo '<div class="wrap">';
		echo '<h1>BH WP Mailboxes — Development</h1>';

		$this->render_notices();
		$this->render_imap_section();
		$this->render_cron_section();
		$this->render_cpt_section();

		echo '</div>';
	}

	/**
	 * Render any success notices after a redirect.
	 */
	private function render_notices(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects.
		if ( isset( $_GET['bh_notice'] ) && 'saved' === $_GET['bh_notice'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>IMAP credentials saved.</p></div>';
		}
		if ( isset( $_GET['bh_fetched'] ) ) {
			$fetched = absint( wp_unslash( $_GET['bh_fetched'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>Fetched ' . esc_html( (string) $fetched ) . ' new email(s).</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render the IMAP test-mailbox credentials form.
	 */
	private function render_imap_section(): void {

		$credentials = new Imap_Credentials_Settings();

		echo '<h2>IMAP test mailbox</h2>';
		echo '<p>Used by the IMAP test mailbox (e.g. in WordPress Playground). Environment variables, when present, take precedence over these values.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_text_field( 'imap_server', 'Server', 'IMAP_SERVER', $credentials->get_email_imap_server() );
		$this->render_text_field( 'imap_username', 'Username', 'IMAP_USERNAME', $credentials->get_email_account_username() );
		$this->render_password_field( $credentials );
		$this->render_encryption_field( $credentials->get_encryption() );

		echo '</tbody></table>';
		submit_button( 'Save IMAP credentials' );
		echo '</form>';
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
			$label         = $status_object instanceof \stdClass && isset( $status_object->label ) ? (string) $status_object->label : $fallback_label;
			$count         = isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;

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

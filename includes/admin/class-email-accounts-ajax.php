<?php
/**
 * AJAX handlers for the accounts table on the emails list view: check now, add/edit, enable/disable, delete.
 *
 * The library stores the account (address, display name, connection class, status) but never
 * credentials. When an IMAP account is saved, the credentials entered in the modal are handed to
 * the consumer through the `bh_wp_mailboxes_save_account_credentials` action, and deleted accounts
 * are announced with `bh_wp_mailboxes_account_deleted` so the consumer can discard them.
 *
 * The actions are suffixed with the accounts CPT so each library instance handles only its own
 * requests (see {@see \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_WP_Mailboxes_Hooks::define_ajax_hooks()}).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Save_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles the accounts table's add/edit, enable/disable and delete requests.
 */
class Email_Accounts_Ajax {

	use LoggerAwareTrait;

	const NONCE_ACTION = 'bh-wp-mailboxes-account-actions';

	const ENCRYPTION_OPTIONS = array( 'TLS', 'STARTTLS', '' );

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api         Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings    Plugin settings.
	 * @param Status_View                        $status_view Renders the refreshed accounts table for the response.
	 * @param LoggerInterface                    $logger      PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Status_View $status_view,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Add or edit an IMAP account from the modal.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_save_account_{accounts_cpt}
	 */
	public function handle_save(): void {
		$this->verify_request();

		try {
			$result = $this->save(
				email_address: $this->post_string( 'email_address' ),
				display_name: $this->post_string( 'display_name' ),
				server: $this->post_string( 'server' ),
				username: $this->post_string( 'username' ),
				password: $this->post_string( 'password' ),
				encryption: $this->post_string( 'encryption' ),
			);
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'Failed to save email account: ' . $exception->getMessage(), array( 'exception' => $exception ) );
			wp_send_json_error( array( 'message' => __( 'The account could not be saved.', 'bh-wp-mailboxes' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'account_post_id' => $result->account->get_post_id(),
				'created'         => $result->created,
				'connection'      => array(
					'success' => $result->connection->success,
					'message' => $result->connection->message,
				),
				'table_html'      => $this->render_table(),
			)
		);
	}

	/**
	 * Save an IMAP account: upsert the account post (keyed by email address), hand the credentials to
	 * the consumer, and test the connection.
	 *
	 * A failed connection test does not discard the account, so the credentials can be corrected by
	 * saving again. When editing, an empty password keeps the one the consumer already holds.
	 *
	 * @param string $email_address The mailbox address; the account's unique id.
	 * @param string $display_name  Human-readable name; defaults to the address.
	 * @param string $server        IMAP server, with optional :port.
	 * @param string $username      Login username; defaults to the address.
	 * @param string $password      Login password (used verbatim).
	 * @param string $encryption    TLS, STARTTLS or empty for none.
	 *
	 * @throws InvalidArgumentException When a required value is missing or invalid.
	 * @throws \Exception When WordPress fails to save the account post.
	 */
	public function save(
		string $email_address,
		string $display_name = '',
		string $server = '',
		string $username = '',
		string $password = '',
		string $encryption = 'TLS',
	): Save_Email_Account_Result {

		$email_address = sanitize_email( trim( $email_address ) );
		$display_name  = sanitize_text_field( $display_name );
		$server        = sanitize_text_field( $server );
		$username      = sanitize_text_field( $username );
		$encryption    = strtoupper( sanitize_text_field( $encryption ) );

		$existing = $this->find_account( $email_address );

		// The upsert is keyed by address, so refuse to turn another connection's account (e.g. the REST
		// ingress) into an IMAP one.
		if ( ! is_null( $existing ) && ImapEngine_Imap_Email_Connection::class !== $existing->connection_type_class ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sent as JSON and rendered as text by the JS; escaping here would double-encode.
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: email address */
					__( '%s is not an IMAP account and cannot be edited here.', 'bh-wp-mailboxes' ),
					$email_address
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Editing: keep the consumer's saved password when none is entered.
		if ( '' === $password && ! is_null( $existing ) ) {
			$saved = $this->get_saved_credentials( $existing );
			if ( ! is_null( $saved ) ) {
				$password = $saved->get_email_account_password();
			}
		}

		$errors = array();
		if ( '' === $email_address || ! is_email( $email_address ) ) {
			$errors[] = __( 'A valid email address is required.', 'bh-wp-mailboxes' );
		}
		if ( '' === $server ) {
			$errors[] = __( 'The IMAP server is required.', 'bh-wp-mailboxes' );
		}
		if ( '' === $password ) {
			$errors[] = __( 'The password is required.', 'bh-wp-mailboxes' );
		}
		if ( ! in_array( $encryption, self::ENCRYPTION_OPTIONS, true ) ) {
			$errors[] = __( 'Encryption must be TLS, STARTTLS or none.', 'bh-wp-mailboxes' );
		}
		if ( count( $errors ) > 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sent as JSON and rendered as text by the JS; escaping here would double-encode.
			throw new InvalidArgumentException( implode( ' ', $errors ) );
		}

		$account = $this->api->configure_email_account(
			$email_address,
			'' === $display_name ? $email_address : $display_name,
			ImapEngine_Imap_Email_Connection::class
		);

		$credentials = new Imap_Credentials( $server, '' === $username ? $email_address : $username, $password, $encryption );

		/**
		 * Persist the credentials entered for an account. The library never stores credentials; the
		 * consumer should save these and return them from the `bh_wp_mailboxes_credentials` filter.
		 *
		 * @param string                        $plugin_slug      The plugin the library instance is running as.
		 * @param string                        $emails_post_type The emails post type key, identifying which mailbox instance fired the action.
		 * @param BH_Email_Account              $account          The saved account.
		 * @param \BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface $credentials The credentials to save ({@see IMAP_Credentials_Interface} for IMAP accounts).
		 */
		do_action( 'bh_wp_mailboxes_save_account_credentials', $this->settings->get_plugin_slug(), $this->settings->get_emails_cpt_underscored_20(), $account, $credentials );

		$this->logger->info( ( is_null( $existing ) ? 'Added' : 'Updated' ) . ' IMAP account ' . $account->display_name );

		return new Save_Email_Account_Result(
			account: $account,
			created: is_null( $existing ),
			connection: $this->api->test_connection( $account, $credentials ),
		);
	}

	/**
	 * Fetch emails for one account now ("Check now"), optionally since a given date (the clock utility).
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_check_account_{accounts_cpt}
	 */
	public function handle_check(): void {
		$this->verify_request();

		$account = $this->post_account();

		if ( ! ( $this->api->get_connection_for_email_account( $account ) instanceof Supports_Fetching ) ) {
			wp_send_json_error( array( 'message' => __( 'This account does not fetch email: emails are delivered to it.', 'bh-wp-mailboxes' ) ), 400 );
		}

		$since     = null;
		$since_raw = sanitize_text_field( $this->post_string( 'since_date' ) );
		if ( '' !== $since_raw ) {
			$since = DateTimeImmutable::createFromFormat( 'Y-m-d', $since_raw, new DateTimeZone( 'UTC' ) );
			if ( false === $since ) {
				wp_send_json_error( array( 'message' => __( 'Unable to parse the since date.', 'bh-wp-mailboxes' ) ), 400 );
			}
		}

		$result = $this->api->check_email_for_account( $account, $since );

		wp_send_json_success(
			array(
				'new_email_count' => count( $result->bh_emails ),
				// Post IDs of the new emails, so the JS can highlight their rows in the list table.
				'new_email_ids'   => array_map( fn( $email ) => $email->get_post_id(), $result->bh_emails ),
				/* translators: shown in the accounts table immediately after a manual check */
				'last_fetched'    => __( 'Just now', 'bh-wp-mailboxes' ),
			)
		);
	}

	/**
	 * Enable or disable an account without deleting it.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_set_account_active_{accounts_cpt}
	 */
	public function handle_set_active(): void {
		$this->verify_request();

		$account = $this->post_account();
		$active  = in_array( $this->post_string( 'active' ), array( '1', 'true' ), true );

		try {
			$updated = $this->api->set_email_account_active( $account->email_address, $active );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'Failed to update email account status: ' . $exception->getMessage(), array( 'exception' => $exception ) );
			wp_send_json_error( array( 'message' => __( 'The account status could not be changed.', 'bh-wp-mailboxes' ) ), 500 );
		}

		if ( is_null( $updated ) ) {
			wp_send_json_error( array( 'message' => __( 'Account not found.', 'bh-wp-mailboxes' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'active'     => $active,
				'table_html' => $this->render_table(),
			)
		);
	}

	/**
	 * Delete an account (locally saved emails are kept) and tell the consumer to discard its credentials.
	 *
	 * Receive-only accounts (e.g. the REST ingress) are created by their endpoint and would be
	 * recreated on the next delivery, so they can only be disabled.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_delete_account_{accounts_cpt}
	 */
	public function handle_delete(): void {
		$this->verify_request();

		$account = $this->post_account();

		if ( ! ( $this->api->get_connection_for_email_account( $account ) instanceof Supports_Fetching ) ) {
			wp_send_json_error( array( 'message' => __( 'This account cannot be deleted: emails are delivered to it. Disable it instead.', 'bh-wp-mailboxes' ) ), 400 );
		}

		if ( ! $this->api->delete_email_account( $account->email_address ) ) {
			wp_send_json_error( array( 'message' => __( 'The account could not be deleted.', 'bh-wp-mailboxes' ) ), 500 );
		}

		/**
		 * An account was deleted; the consumer should discard any credentials it holds for it.
		 *
		 * @param string           $plugin_slug      The plugin the library instance is running as.
		 * @param string           $emails_post_type The emails post type key, identifying which mailbox instance fired the action.
		 * @param BH_Email_Account $account          The deleted account.
		 */
		do_action( 'bh_wp_mailboxes_account_deleted', $this->settings->get_plugin_slug(), $this->settings->get_emails_cpt_underscored_20(), $account );

		$this->logger->info( 'Deleted email account ' . $account->display_name );

		wp_send_json_success( array( 'table_html' => $this->render_table() ) );
	}

	/**
	 * The refreshed accounts table, for the JS to swap in place.
	 */
	protected function render_table(): string {
		ob_start();
		$this->status_view->render_table();

		return (string) ob_get_clean();
	}

	/**
	 * Die unless the request carries the accounts-actions nonce and the user may manage accounts.
	 */
	protected function verify_request(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or expired request; reload the page and try again.', 'bh-wp-mailboxes' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage email accounts.', 'bh-wp-mailboxes' ) ), 403 );
		}
	}

	/**
	 * The account identified by the POSTed `account_post_id`, or die with 404.
	 */
	protected function post_account(): BH_Email_Account {
		$post_id = (int) $this->post_string( 'account_post_id' );

		foreach ( $this->api->get_email_accounts() as $account ) {
			if ( $account->get_post_id() === $post_id ) {
				return $account;
			}
		}

		wp_send_json_error( array( 'message' => __( 'Account not found.', 'bh-wp-mailboxes' ) ), 404 );
	}

	/**
	 * The account for an email address, or null.
	 *
	 * @param string $email_address The account's email address.
	 */
	protected function find_account( string $email_address ): ?BH_Email_Account {
		foreach ( $this->api->get_email_accounts() as $account ) {
			if ( strcasecmp( $account->email_address, $email_address ) === 0 ) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * The consumer's saved IMAP credentials for an account, if any.
	 *
	 * @param BH_Email_Account $account The account.
	 */
	protected function get_saved_credentials( BH_Email_Account $account ): ?IMAP_Credentials_Interface {
		$credentials = apply_filters( 'bh_wp_mailboxes_credentials', null, $this->settings->get_plugin_slug(), $this->settings->get_emails_cpt_underscored_20(), $account );

		return $credentials instanceof IMAP_Credentials_Interface ? $credentials : null;
	}

	/**
	 * An unslashed POST string, or empty string. The nonce is verified in {@see verify_request()} first.
	 *
	 * @param string $key The POST key.
	 */
	protected function post_string( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by the caller; sanitized per field (passwords are used verbatim).
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
}

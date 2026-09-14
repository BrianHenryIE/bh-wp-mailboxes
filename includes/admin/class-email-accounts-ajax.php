<?php
/**
 * AJAX handlers for the accounts table on the emails list view: check now, add/edit (and its "Test connection"), enable/disable, delete.
 *
 * The account (address, display name, connection class, status) is a post; the credentials entered
 * in the modal are saved through {@see API_Interface::save_account_credentials()} (encrypted, via the
 * WordPress Secrets API) and discarded with the account.
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
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Test_Connection_Result;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
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
		$this->manager = new Email_Account_Manager( $api, $settings, $logger );
	}

	/**
	 * Validates, saves and tests accounts; shared with the REST controller.
	 *
	 * @var Email_Account_Manager
	 */
	protected Email_Account_Manager $manager;

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
				validate_cert: $this->post_bool( 'validate_cert', true ),
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
	 * Save an IMAP account ({@see Email_Account_Manager::save()}).
	 *
	 * @param string $email_address The mailbox address; the account's unique id.
	 * @param string $display_name  Human-readable name; defaults to the address.
	 * @param string $server        IMAP server, with optional :port.
	 * @param string $username      Login username; defaults to the address.
	 * @param string $password      Login password (used verbatim).
	 * @param string $encryption    TLS, STARTTLS or empty for none.
	 * @param bool   $validate_cert Whether to verify the server's TLS certificate.
	 *
	 * @throws InvalidArgumentException When a required value is missing or invalid.
	 * @throws \Exception When WordPress fails to save the account post, or the credentials cannot be saved.
	 */
	public function save(
		string $email_address,
		string $display_name = '',
		string $server = '',
		string $username = '',
		string $password = '',
		string $encryption = 'TLS',
		bool $validate_cert = true,
	): Save_Email_Account_Result {
		return $this->manager->save( $email_address, $display_name, $server, $username, $password, $encryption, $validate_cert );
	}

	/**
	 * Test the IMAP details entered in the modal without saving anything ("Test connection").
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_test_account_connection_{accounts_cpt}
	 */
	public function handle_test_connection(): void {
		$this->verify_request();

		try {
			$result = $this->test_connection(
				email_address: $this->post_string( 'email_address' ),
				display_name: $this->post_string( 'display_name' ),
				server: $this->post_string( 'server' ),
				username: $this->post_string( 'username' ),
				password: $this->post_string( 'password' ),
				encryption: $this->post_string( 'encryption' ),
				validate_cert: $this->post_bool( 'validate_cert', true ),
			);
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}

		if ( $result->success ) {
			wp_send_json_success( array( 'message' => $result->message ) );
		}

		// A refused login or unreachable server is the expected outcome being reported, not a request error.
		wp_send_json_error( array( 'message' => $result->message ), 200 );
	}

	/**
	 * Test IMAP credentials without saving ({@see Email_Account_Manager::test_connection()}).
	 *
	 * @param string $email_address The mailbox address.
	 * @param string $display_name  Human-readable name; defaults to the address.
	 * @param string $server        IMAP server, with optional :port.
	 * @param string $username      Login username; defaults to the address.
	 * @param string $password      Login password (used verbatim).
	 * @param string $encryption    TLS, STARTTLS or empty for none.
	 * @param bool   $validate_cert Whether to verify the server's TLS certificate.
	 *
	 * @throws InvalidArgumentException When a required value is missing or invalid.
	 */
	public function test_connection(
		string $email_address,
		string $display_name = '',
		string $server = '',
		string $username = '',
		string $password = '',
		string $encryption = 'TLS',
		bool $validate_cert = true,
	): Test_Connection_Result {
		return $this->manager->test_connection( $email_address, $display_name, $server, $username, $password, $encryption, $validate_cert );
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

		if ( ! $result->success ) {
			wp_send_json_error(
				array(
					'message'    => $result->message ?? __( 'Check failed.', 'bh-wp-mailboxes' ),
					'status'     => $result->skipped ? 'skipped' : 'failed',
					// The re-rendered table shows the failure time and login-failure badge on the account's row.
					'table_html' => $this->render_table(),
				)
			);
		}

		wp_send_json_success(
			array(
				'new_email_count' => count( $result->bh_emails ),
				// Post IDs of the new emails, so the JS can highlight their rows in the list table.
				'new_email_ids'   => array_map( fn( $email ) => $email->get_post_id(), $result->bh_emails ),
				'warnings'        => $result->warnings,
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
	 * Delete an account and its credentials (locally saved emails are kept).
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
	 * A POSTed boolean: `1`/`true` is true, any other value false; the default when the key is absent
	 * (e.g. a caller that does not know the field).
	 *
	 * @param string $key      The POST key.
	 * @param bool   $fallback The value when the key is not posted.
	 */
	protected function post_bool( string $key, bool $fallback ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback;
		}

		return in_array( $this->post_string( $key ), array( '1', 'true' ), true );
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

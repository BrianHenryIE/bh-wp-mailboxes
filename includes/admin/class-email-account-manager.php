<?php
/**
 * Saves and tests IMAP accounts from the add/edit form's fields: the logic behind the REST account routes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\Admin\Model\Email_Account_Input;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Save_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Test_Connection_Result;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Validates the form fields, upserts the account and its credentials, and tests connections.
 */
class Email_Account_Manager {

	use LoggerAwareTrait;

	const ENCRYPTION_OPTIONS = array( 'TLS', 'STARTTLS', '' );

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api      Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings.
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Save an IMAP account: upsert the account post (keyed by email address), save the credentials,
	 * and test the connection.
	 *
	 * A failed connection test does not discard the account, so the credentials can be corrected by
	 * saving again. When editing, an empty password keeps the saved one.
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

		$input    = $this->validate( $email_address, $display_name, $server, $username, $password, $encryption, $validate_cert );
		$existing = $input->existing;

		$account = $this->api->configure_email_account(
			$input->email_address,
			$input->display_name,
			ImapEngine_Imap_Email_Connection::class
		);

		$credentials = new Imap_Credentials( $input->server, $input->username, $input->password, $input->encryption, $input->validate_cert );

		$this->api->save_account_credentials( $account, $credentials );

		$this->logger->info( ( is_null( $existing ) ? 'Added' : 'Updated' ) . ' IMAP account ' . $account->display_name );

		return new Save_Email_Account_Result(
			account: $account,
			created: is_null( $existing ),
			connection: $this->api->test_connection( $account, $credentials ),
		);
	}

	/**
	 * Test IMAP credentials as entered in the form, without saving the account or the credentials.
	 *
	 * Uses the same validation as {@see self::save()}, including keeping the saved password when
	 * editing with a blank one. For an address that is not yet an account, a transient (unsaved)
	 * account is used to resolve the connection.
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

		$input       = $this->validate( $email_address, $display_name, $server, $username, $password, $encryption, $validate_cert );
		$credentials = new Imap_Credentials( $input->server, $input->username, $input->password, $input->encryption, $input->validate_cert );

		$account = $input->existing ?? new BH_Email_Account(
			post_id: 0,
			post_type: $this->settings->get_email_accounts_cpt_underscored_20(),
			local_status: 'bh_email_ac_active',
			connection_type_class: ImapEngine_Imap_Email_Connection::class,
			email_address: $input->email_address,
			display_name: $input->display_name,
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: null,
			total_emails_downloaded_count: 0,
			total_emails_saved_count: 0,
			last_checked_time: null,
			last_successful_login_time: null,
			last_failed_login_time: null,
		);

		return $this->api->test_connection( $account, $credentials );
	}

	/**
	 * The account with this post id, or null when this mailbox has no such account.
	 *
	 * @param int $post_id The account post id.
	 */
	public function find_account_by_post_id( int $post_id ): ?BH_Email_Account {
		foreach ( $this->api->get_email_accounts() as $account ) {
			if ( $account->get_post_id() === $post_id ) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * Sanitise and validate the form's fields, applying the defaults (display name and username
	 * default to the address; a blank password when editing keeps the saved one).
	 *
	 * @param string $email_address The mailbox address.
	 * @param string $display_name  Human-readable name.
	 * @param string $server        IMAP server, with optional :port.
	 * @param string $username      Login username.
	 * @param string $password      Login password.
	 * @param string $encryption    TLS, STARTTLS or empty for none.
	 * @param bool   $validate_cert Whether to verify the server's TLS certificate.
	 *
	 * @throws InvalidArgumentException When a required value is missing or invalid, or the address belongs to a non-IMAP account.
	 */
	public function validate( string $email_address, string $display_name, string $server, string $username, string $password, string $encryption, bool $validate_cert = true ): Email_Account_Input {

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

		// Editing: keep the saved password when none is entered.
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

		return new Email_Account_Input(
			email_address: $email_address,
			display_name: '' === $display_name ? $email_address : $display_name,
			server: $server,
			username: '' === $username ? $email_address : $username,
			password: $password,
			encryption: $encryption,
			validate_cert: $validate_cert,
			existing: $existing,
		);
	}

	/**
	 * The account for an email address, or null.
	 *
	 * @param string $email_address The account's email address.
	 */
	public function find_account( string $email_address ): ?BH_Email_Account {
		foreach ( $this->api->get_email_accounts() as $account ) {
			if ( strcasecmp( $account->email_address, $email_address ) === 0 ) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * The saved IMAP credentials for an account, if any.
	 *
	 * @param BH_Email_Account $account The account.
	 */
	protected function get_saved_credentials( BH_Email_Account $account ): ?IMAP_Credentials_Interface {
		$credentials = $this->api->get_account_credentials( $account );

		return $credentials instanceof IMAP_Credentials_Interface ? $credentials : null;
	}
}

<?php
/**
 * IMAP credentials value object.
 *
 * The library never stores credentials; this carries them from the accounts admin UI to the
 * consumer (via the `bh_wp_mailboxes_save_account_credentials` action) and to the connection.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

/**
 * Server, username, password and encryption for one IMAP account.
 */
readonly class Imap_Credentials implements IMAP_Credentials_Interface {

	/**
	 * Constructor.
	 *
	 * @param string $server     IMAP server hostname or IP, with optional `:port`.
	 * @param string $username   Login username, usually the email address.
	 * @param string $password   Login password.
	 * @param string $encryption `TLS`, `STARTTLS`, or empty string for none.
	 */
	public function __construct(
		protected string $server,
		protected string $username,
		protected string $password,
		protected string $encryption = 'TLS',
	) {
	}

	/**
	 * IMAP server domain name or IP address with optional :port number.
	 */
	public function get_email_imap_server(): string {
		return $this->server;
	}

	/**
	 * IMAP username. Probably in the format username@example.org.
	 */
	public function get_email_account_username(): string {
		return $this->username;
	}

	/**
	 * Password for logging on to IMAP server.
	 */
	public function get_email_account_password(): string {
		return $this->password;
	}

	/**
	 * TLS, STARTTLS, '' empty string for none.
	 */
	public function get_encryption(): string {
		return $this->encryption;
	}
}

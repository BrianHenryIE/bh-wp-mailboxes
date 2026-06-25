<?php
/**
 * Interface for IMAP server credentials.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;

interface IMAP_Credentials_Interface extends Account_Credentials_Interface {

	/**
	 * IMAP server domain name or IP address with optional :port number.
	 *
	 * @return string
	 */
	public function get_email_imap_server(): string;

	/**
	 * IMAP username. Probably in the format username@example.org.
	 *
	 * @return string
	 */
	public function get_email_account_username(): string;

	/**
	 * Password for logging on to IMAP server.
	 *
	 * @return string
	 */
	public function get_email_account_password(): string;

	/**
	 * TLS, STARTTLS, '' empty string for none.
	 */
	public function get_encryption(): string;
}

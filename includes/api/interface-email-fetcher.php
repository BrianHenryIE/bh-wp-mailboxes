<?php
/**
 * Defines an interface to allow different IMAP libraries to be used.
 *
 * `Email_Fetcher`s should catch and wrap libraries' exceptions in local exceptions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface Email_Fetcher_Interface {

	public function set_credentials( Account_Credentials_Interface $credentials ): void;

	/**
	 * Typically, check for emails since the time of the last email or the last time emails were checked for.
	 *
	 * @param DateTimeInterface $since_time The earliest date to retrieve emails from.
	 *
	 * @return Collection<int, \ZBateson\MailMimeParser\IMessage> Unsaved emails.
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): Collection;

	/**
	 * Test the account connection without returning emails.
	 */
	// `public function test_connection();`.

	/**
	 * Does the email service support reading the read/unread status of emails on the server.
	 */
	public function can_read_status(): bool;

	/**
	 * Does the email service support changing the read/unread status on the server.
	 */
	public function can_mark_read(): bool;

	/**
	 * Does the service supports deleting the remote emails.
	 */
	public function can_delete_on_server(): bool;

	/**
	 * Delete the email on the remote server.
	 *
	 * @throws \Exception When the email service does not support the operation or the user does not have permission.
	 */
	// `public function do_delete_on_server(): bool;`.
}

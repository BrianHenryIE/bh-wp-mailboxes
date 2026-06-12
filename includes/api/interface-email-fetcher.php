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
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface Email_Fetcher_Interface {

	/**
	 * Set credentials (and presumably connect if relevant).
	 *
	 * Can be no-op (until we create a super-class/interface for all email services).
	 *
	 * @param Account_Credentials_Interface $credentials From the `bh_wp_mailboxes_credentials` filter.
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void;

	/**
	 * Typically, check for emails since the time of the last email or the last time emails were checked for.
	 *
	 * @param DateTimeInterface $since_time The earliest time to retrieve emails from.
	 *
	 * @return Collection<int, \BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email> Unsaved emails with their remote coordinates.
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
	 * Make an API call to determine whether the email is marked read on the server.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 */
	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool;

	/**
	 * Does the email service support changing the read/unread status on the server.
	 */
	public function can_mark_read(): bool;

	/**
	 * Perform API call to change the read status of an email on a server.
	 *
	 * TODO: what return value? Assume success and throw on error?
	 */
	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void;

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

<?php
/**
 * Defines an interface to allow different IMAP libraries to be used.
 *
 * `Email_Fetcher`s should catch and wrap libraries' exceptions in local exceptions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;

interface Email_Provider_Interface {

	/**
	 * Connect to the server and verify the credentials authenticate, without returning emails.
	 *
	 * @return bool True when the connection and authentication succeed.
	 * @throws \Throwable When the connection or authentication fails.
	 */
	public function test_connection(): bool;

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
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 * @param bool                     $is_read Mark as read or false for unread.
	 */
	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void;

	/**
	 * Does the service supports deleting the remote emails.
	 */
	public function can_delete_on_server(): bool;

	/**
	 * Delete the email on the remote server.
	 *
	 * @param Remote_Email_Coordinates $coordinates The data required to address a single email.
	 *
	 * @throws \Exception When the email service does not support the operation or the user does not have permission.
	 */
	public function do_delete_on_server( Remote_Email_Coordinates $coordinates ): bool;
}

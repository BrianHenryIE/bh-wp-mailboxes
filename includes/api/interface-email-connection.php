<?php
/**
 * Defines an interface to allow different IMAP libraries to be used.
 *
 * `Email_Fetcher`s should catch and wrap libraries' exceptions in local exceptions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

interface Email_Connection_Interface {

	/**
	 * Connect to the server and verify the credentials authenticate, without returning emails.
	 *
	 * @return bool True when the connection and authentication succeed.
	 * @throws \Throwable When the connection or authentication fails.
	 */
	public function test_connection(): bool;
}

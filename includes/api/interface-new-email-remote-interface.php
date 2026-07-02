<?php
/**
 * Remote (server-side) actions for a downloaded email: delete, mark read, mark unread.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

interface New_Email_Remote_Interface {

	/**
	 * Delete the email on the server via a remote IMAP/API call.
	 */
	public function delete_on_server(): self;

	/**
	 * Mark the email read on the server via a remote IMAP/API call.
	 */
	public function mark_read_on_server(): self;

	/**
	 * Mark the email as unread on the server via a remote IMAP/API call.
	 */
	public function mark_unread_on_server(): self;
}

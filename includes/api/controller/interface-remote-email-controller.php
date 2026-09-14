<?php
/**
 * An email whose account's connection can act on the mail server: delete, mark read, mark unread, and
 * query the live read status.
 *
 * The `can_*` predicates mirror the connection's ({@see \BrianHenryIE\WP_Mailboxes\API\Supports_Fetching});
 * they decide which commands the REST routes accept and which buttons the admin screens render.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Controller;

use BrianHenryIE\WP_Mailboxes\API\Exceptions\Invalid_Email_State_Exception;

interface Remote_Email_Controller_Interface extends Email_Controller_Interface {

	/**
	 * Whether the connection can mark emails read/unread on the server.
	 */
	public function can_mark_read_on_server(): bool;

	/**
	 * Whether the connection can delete emails on the server.
	 */
	public function can_delete_on_server(): bool;

	/**
	 * Whether the connection can report an email's live read status.
	 */
	public function can_read_remote_status(): bool;

	/**
	 * The live read status on the server; null when it cannot be determined.
	 */
	public function get_remote_read_status(): ?bool;

	/**
	 * Delete the email on the server via a remote IMAP/API call.
	 *
	 * @throws Invalid_Email_State_Exception When the email is already deleted on the server.
	 */
	public function delete_on_server(): static;

	/**
	 * Mark the email read on the server via a remote IMAP/API call.
	 *
	 * @throws Invalid_Email_State_Exception When the email is recorded as already read, or deleted on the server.
	 */
	public function mark_read_on_server(): static;

	/**
	 * Mark the email as unread on the server via a remote IMAP/API call.
	 *
	 * @throws Invalid_Email_State_Exception When the email is recorded as already unread, or deleted on the server.
	 */
	public function mark_unread_on_server(): static;
}

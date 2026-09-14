<?php
/**
 * An email whose account's connection can act on the mail server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Controller;

use BrianHenryIE\WP_Mailboxes\API\Exceptions\Invalid_Email_State_Exception;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Remote_Interface;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;

/**
 * Adds the remote verbs and the connection's `can_*` predicates to the local controller.
 */
readonly class Remote_Email_Controller extends Local_Email_Controller implements New_Email_Remote_Interface {

	/**
	 * Whether the connection can mark emails read/unread on the server.
	 */
	public function can_mark_read_on_server(): bool {
		return $this->connection()?->can_mark_read() ?? false;
	}

	/**
	 * Whether the connection can delete emails on the server.
	 */
	public function can_delete_on_server(): bool {
		return $this->connection()?->can_delete_on_server() ?? false;
	}

	/**
	 * Whether the connection can report an email's live read status.
	 */
	public function can_read_remote_status(): bool {
		return $this->connection()?->can_read_status() ?? false;
	}

	/**
	 * The live read status on the server; null when it cannot be determined.
	 */
	public function get_remote_read_status(): ?bool {
		return $this->api->get_remote_read_status( $this->email );
	}

	/**
	 * Delete the email on the server via a remote IMAP/API call.
	 *
	 * @throws Invalid_Email_State_Exception When the email is already deleted on the server.
	 */
	public function delete_on_server(): static {
		$this->assert_not_deleted_on_server();

		return $this->with_email( $this->api->delete_email_on_server( email: $this->email ) );
	}

	/**
	 * Mark the email read on the server via a remote IMAP/API call.
	 *
	 * @throws Invalid_Email_State_Exception When the email is recorded as already read, or deleted on the server.
	 */
	public function mark_read_on_server(): static {
		$this->assert_not_deleted_on_server();
		if ( true === $this->email->is_remote_read ) {
			throw new Invalid_Email_State_Exception( 'The email is already marked read on the server.' );
		}

		return $this->with_email( $this->api->mark_email_read( email: $this->email ) );
	}

	/**
	 * Mark the email as unread on the server via a remote IMAP/API call.
	 *
	 * @throws Invalid_Email_State_Exception When the email is recorded as already unread, or deleted on the server.
	 */
	public function mark_unread_on_server(): static {
		$this->assert_not_deleted_on_server();
		if ( false === $this->email->is_remote_read ) {
			throw new Invalid_Email_State_Exception( 'The email is already marked unread on the server.' );
		}

		return $this->with_email( $this->api->mark_email_unread( email: $this->email ) );
	}

	/**
	 * Nothing can be changed on the server once the email is deleted there.
	 *
	 * @throws Invalid_Email_State_Exception When the email is recorded as deleted on the server.
	 */
	protected function assert_not_deleted_on_server(): void {
		if ( true === $this->email->is_remote_deleted ) {
			throw new Invalid_Email_State_Exception( 'The email has already been deleted on the server.' );
		}
	}

	/**
	 * The account's connection, when it supports remote actions.
	 */
	protected function connection(): ?Supports_Fetching {
		$account = $this->api->get_email_account_for_email( $this->email );
		if ( is_null( $account ) ) {
			return null;
		}
		$connection = $this->api->get_connection_for_email_account( $account );

		return $connection instanceof Supports_Fetching ? $connection : null;
	}
}

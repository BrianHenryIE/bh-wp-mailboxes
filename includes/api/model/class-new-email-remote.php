<?php
/**
 * When firing the action, we wrap emails in this class so consumers have an easy way to invoke functions on the email.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use BrianHenryIE\WP_Mailboxes\API\New_Email_Remote_Interface;

/**
 * A downloaded email wrapper that additionally supports remote server actions: delete, mark read/unread.
 */
readonly class New_Email_Remote extends New_Email_Local implements New_Email_Remote_Interface {

	/**
	 * Delete the email on the server via a remote IMAP/API call.
	 */
	public function delete_on_server(): self {
		return new self(
			email: $this->api->delete_email_on_server( email: $this->email ),
			api: $this->api,
		);
	}

	/**
	 * Mark the email read on the server via a remote IMAP/API call.
	 */
	public function mark_read_on_server(): self {
		return new self(
			email: $this->api->mark_email_read( email: $this->email ),
			api: $this->api,
		);
	}

	/**
	 * Mark the email as unread on the server via a remote IMAP/API call.
	 */
	public function mark_unread_on_server(): self {
		return new self(
			email: $this->api->mark_email_unread( email: $this->email ),
			api: $this->api,
		);
	}
}

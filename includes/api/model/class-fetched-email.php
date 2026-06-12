<?php
/**
 * A freshly fetched email paired with the data needed to save it: its parsed MIME message, the
 * coordinates to locate it again on the server, and its current read state.
 *
 * `retrieve_emails()` returns these so the UID/folder/UIDVALIDITY captured during the fetch (which
 * the parsed `IMessage` cannot carry) survive through to post meta.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use ZBateson\MailMimeParser\IMessage;

/**
 * Immutable pairing of a parsed email with its remote coordinates and read state.
 */
readonly class Fetched_Email {

	/**
	 * Constructor.
	 *
	 * @param IMessage                 $message        The parsed email (excluding attachments).
	 * @param Remote_Email_Coordinates $coordinates    How to locate this email on the remote server.
	 * @param bool                     $is_remote_read Whether the email was marked read on the server at fetch time.
	 */
	public function __construct(
		public IMessage $message,
		public Remote_Email_Coordinates $coordinates,
		public bool $is_remote_read,
	) {}
}

<?php
/**
 * A provider-agnostic handle for locating an email on its remote server.
 *
 * The RFC822 `Message-ID` is globally unique but is not an addressable IMAP identifier: a message
 * is addressed by its UID within a `(mailbox, UIDVALIDITY)` scope. This value object carries both,
 * so read-status lookups can FETCH directly by UID and fall back to a `Message-ID` header search
 * when the UID is unknown or stale (folder move / UIDVALIDITY reset).
 *
 * For Gmail, `remote_uid` holds the Gmail message id and `folder`/`uid_validity` are null.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;

/**
 * Immutable coordinates identifying an email on the remote server.
 *
 * @see Email_WP_Post_Repository::message_id_slug()
 */
readonly class Remote_Email_Coordinates {

	/**
	 * Constructor.
	 *
	 * @param string  $message_id   The RFC822 Message-ID header value (used for the fallback search).
	 * @param ?string $remote_uid   Connection-native id: IMAP UID (as a string) or Gmail message id; null when unknown.
	 * @param ?string $folder       IMAP folder/mailbox path the UID belongs to; null for Gmail.
	 * @param ?int    $uid_validity IMAP UIDVALIDITY of the folder when the UID was captured; null for Gmail.
	 */
	public function __construct(
		public string $message_id,
		public ?string $remote_uid = null,
		public ?string $folder = null,
		public ?int $uid_validity = null,
	) {}
}

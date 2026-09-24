<?php
/**
 * How many of an account's stored emails are in each local status.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;

/**
 * Counts of an account's non-trashed email posts by status.
 *
 * @see BH_Email_CPT::register_post_statuses()
 */
readonly class Email_Status_Counts {

	/**
	 * Constructor.
	 *
	 * @param int $new_count       Emails in `bh_email_new`: downloaded but not yet handled by a consumer.
	 * @param int $processed_count Emails in `bh_email_processed`.
	 * @param int $saved_count     Emails in `bh_email_saved`: kept indefinitely.
	 * @param int $other_count     Emails in any other non-trash status (e.g. `publish`, `draft`).
	 */
	public function __construct(
		public int $new_count = 0,
		public int $processed_count = 0,
		public int $saved_count = 0,
		public int $other_count = 0,
	) {}

	/**
	 * All non-trashed emails for the account.
	 */
	public function total(): int {
		return $this->new_count + $this->processed_count + $this->saved_count + $this->other_count;
	}
}

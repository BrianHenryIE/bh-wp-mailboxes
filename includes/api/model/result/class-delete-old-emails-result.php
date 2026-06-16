<?php
/**
 * Result of deleting locally-stored emails older than the configured retention period.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model\Result;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;

/**
 * Returned by {@see API_Interface::delete_old_emails()}.
 */
readonly class Delete_Old_Emails_Result {

	/**
	 * Constructor.
	 *
	 * @param bool $success       Whether the deletion ran.
	 * @param int  $deleted_count The number of emails deleted.
	 */
	public function __construct(
		public bool $success,
		public int $deleted_count,
	) {}
}

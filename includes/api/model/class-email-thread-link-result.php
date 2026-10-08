<?php
/**
 * The outcome of linking a newly saved email into a thread.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Model;

/**
 * Immutable result of {@see \BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker::link()}.
 */
readonly class Email_Thread_Link_Result {

	/**
	 * Constructor.
	 *
	 * @param int   $term_id         The thread term the email now belongs to.
	 * @param bool  $is_new_thread   Whether the term was created for this email (no related emails were found).
	 * @param int[] $merged_post_ids Emails moved into this thread from other threads that this email bridged.
	 */
	public function __construct(
		public int $term_id,
		public bool $is_new_thread,
		public array $merged_post_ids = array(),
	) {}
}

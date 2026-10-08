<?php
/**
 * A conversation thread: the set of stored emails linked by their Message-ID / In-Reply-To / References headers.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Model;

/**
 * Immutable value object for a thread of emails.
 */
readonly class Email_Thread {

	/**
	 * Constructor.
	 *
	 * @param int        $term_id The thread's taxonomy term id.
	 * @param string     $slug    The term slug: a hash of the thread's root Message-ID.
	 * @param BH_Email[] $emails  The emails in the thread, oldest first by sent date.
	 */
	public function __construct(
		public int $term_id,
		public string $slug,
		public array $emails = array(),
	) {}

	/**
	 * How many emails are in the thread.
	 */
	public function count(): int {
		return count( $this->emails );
	}

	/**
	 * Whether the thread has more than one email, i.e. whether there is anything to show.
	 */
	public function has_related_emails(): bool {
		return $this->count() > 1;
	}
}

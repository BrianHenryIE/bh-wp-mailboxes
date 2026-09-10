<?php
/**
 * Result of checking one account for new emails.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model\Result;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

/**
 * Returned by {@see API_Interface::check_email_for_account()}.
 *
 * Exactly one of three outcomes: the fetch completed (`success`, possibly with `warnings` about
 * follow-up steps that failed), the account was skipped on purpose (`skipped`, e.g. disabled or
 * receive-only), or the fetch failed (`! success && ! skipped`, e.g. bad credentials or an
 * unreachable server). `message` explains a skip or failure in user-facing terms.
 */
readonly class Check_Email_Account_Result {

	/**
	 * Constructor.
	 *
	 * @param BH_Email_Account      $bh_account The account just checked.
	 * @param bool                  $success    Whether the fetch completed.
	 * @param BH_Email[]            $bh_emails  The emails newly saved during this check.
	 * @param New_Email_Interface[] $new_emails The newly saved emails wrapped for consumers.
	 * @param bool                  $skipped    Whether the account was deliberately not checked (disabled, receive-only, rate-limited).
	 * @param ?string               $message    Why the check was skipped or failed; null on success.
	 * @param string[]              $warnings   Problems after a successful fetch (e.g. a post-download action or a credentials refresh that could not be saved).
	 */
	public function __construct(
		public BH_Email_Account $bh_account,
		public bool $success,
		public array $bh_emails = array(),
		public array $new_emails = array(),
		public bool $skipped = false,
		public ?string $message = null,
		public array $warnings = array(),
	) {}

	/**
	 * The fetch was attempted and did not complete.
	 */
	public function is_failure(): bool {
		return ! $this->success && ! $this->skipped;
	}

	/**
	 * A copy with the consumer-facing wrappers for the saved emails filled in.
	 *
	 * @param New_Email_Interface[] $new_emails The wrapped emails.
	 */
	public function with_new_emails( array $new_emails ): self {
		return new self( $this->bh_account, $this->success, $this->bh_emails, $new_emails, $this->skipped, $this->message, $this->warnings );
	}
}

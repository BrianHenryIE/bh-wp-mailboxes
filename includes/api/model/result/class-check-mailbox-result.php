<?php
/**
 * Result of checking one or more mailboxes for new emails.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model\Result;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

/**
 * Returned by {@see API_Interface::check_email()}.
 */
readonly class Check_Mailbox_Result {

	/**
	 * Constructor.
	 *
	 * @param bool                         $success         Whether every attempted account check completed (skipped accounts do not count as failures).
	 * @param BH_Email_Account[]           $accounts        The accounts that were checked.
	 * @param Check_Email_Account_Result[] $account_results The per-account results.
	 */
	public function __construct(
		public bool $success,
		public array $accounts,
		public array $account_results,
	) {}

	/**
	 * Flatten the per-account results into a single list of newly saved emails.
	 *
	 * @return New_Email_Interface[]
	 */
	public function get_emails(): array {
		$emails = array_map(
			fn( Check_Email_Account_Result $account_result ): array => $account_result->new_emails,
			$this->account_results
		);

		return array_merge( ...$emails );
	}

	/**
	 * The accounts whose fetch was attempted and did not complete.
	 *
	 * @return Check_Email_Account_Result[]
	 */
	public function get_failures(): array {
		return array_values( array_filter( $this->account_results, fn( Check_Email_Account_Result $result ): bool => $result->is_failure() ) );
	}

	/**
	 * The accounts that were deliberately not checked.
	 *
	 * @return Check_Email_Account_Result[]
	 */
	public function get_skipped(): array {
		return array_values( array_filter( $this->account_results, fn( Check_Email_Account_Result $result ): bool => $result->skipped ) );
	}
}

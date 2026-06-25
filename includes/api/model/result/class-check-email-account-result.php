<?php
/**
 * Result of checking one or more mailboxes for new emails.
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
 */
readonly class Check_Email_Account_Result {

	/**
	 * Constructor.
	 *
	 * @param BH_Email_Account      $bh_account The account just checked.
	 * @param bool                  $success    Whether the check completed.
	 * @param BH_Email[]            $bh_emails  The emails newly saved during this check.
	 * @param New_Email_Interface[] $new_emails The newly saved emails wrapped for consumers.
	 */
	public function __construct(
		public BH_Email_Account $bh_account,
		public bool $success,
		public array $bh_emails = array(),
		public array $new_emails = array(),
	) {}
}

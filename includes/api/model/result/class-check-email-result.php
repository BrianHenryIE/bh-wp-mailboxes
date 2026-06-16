<?php
/**
 * Result of checking one or more mailboxes for new emails.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model\Result;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;

/**
 * Returned by {@see API_Interface::check_email()} and {@see API_Interface::check_email_for_account()}.
 */
readonly class Check_Email_Result {

	/**
	 * Constructor.
	 *
	 * @param bool       $success    Whether the check completed.
	 * @param BH_Email[] $new_emails The emails newly saved during this check.
	 */
	public function __construct(
		public bool $success,
		public array $new_emails,
	) {}
}

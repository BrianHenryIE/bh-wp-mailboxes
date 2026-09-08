<?php
/**
 * Result of saving an email account from the accounts admin UI.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Model\Result;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

/**
 * Returned by {@see \BrianHenryIE\WP_Mailboxes\Admin\Email_Accounts_Ajax::save()}.
 */
readonly class Save_Email_Account_Result {

	/**
	 * Constructor.
	 *
	 * @param BH_Email_Account       $account    The saved account.
	 * @param bool                   $created    True when the account was newly created; false when updated.
	 * @param Test_Connection_Result $connection The result of testing the connection with the saved credentials.
	 */
	public function __construct(
		public BH_Email_Account $account,
		public bool $created,
		public Test_Connection_Result $connection,
	) {}
}

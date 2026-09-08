<?php
/**
 * One row of the accounts table: the account plus what its row needs that the account itself does not know.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin\Model;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;

/**
 * Built by {@see \BrianHenryIE\WP_Mailboxes\Admin\Status_View}, rendered by
 * {@see \BrianHenryIE\WP_Mailboxes\Admin\Email_Accounts_List_Table}.
 */
readonly class Email_Account_Row {

	/**
	 * Constructor.
	 *
	 * @param BH_Email_Account            $account           The account.
	 * @param string                      $connection_label  Short connection name, e.g. "IMAP", "REST Ingress".
	 * @param bool                        $supports_fetching Can the connection fetch (pull) emails? False for receive-only connections.
	 * @param bool                        $can_edit          Is this an IMAP account whose credentials the consumer manages? True only then.
	 * @param ?IMAP_Credentials_Interface $credentials       The consumer's credentials, for pre-filling the edit form (password never printed).
	 * @param int                         $email_count       Emails saved for the account.
	 * @param bool                        $has_login_failure Did the most recent login attempt fail? Shown as a warning.
	 * @param string                      $since_value       Default for the set-fetch-since date input, Y-m-d.
	 */
	public function __construct(
		public BH_Email_Account $account,
		public string $connection_label,
		public bool $supports_fetching,
		public bool $can_edit,
		public ?IMAP_Credentials_Interface $credentials,
		public int $email_count,
		public bool $has_login_failure,
		public string $since_value,
	) {}
}

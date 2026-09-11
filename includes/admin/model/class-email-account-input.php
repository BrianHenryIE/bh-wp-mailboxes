<?php
/**
 * The validated fields of the add/edit IMAP account modal.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin\Model;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

/**
 * Built by {@see \BrianHenryIE\WP_Mailboxes\Admin\Email_Accounts_Ajax::validate()} for both saving
 * and testing a connection, with the form's defaults applied (display name and username default to
 * the address; a blank password when editing is replaced by the saved one).
 */
readonly class Email_Account_Input {

	/**
	 * Constructor.
	 *
	 * @param string            $email_address The mailbox address.
	 * @param string            $display_name  Human-readable name.
	 * @param string            $server        IMAP server, with optional :port.
	 * @param string            $username      Login username.
	 * @param string            $password      Login password.
	 * @param string            $encryption    TLS, STARTTLS or empty for none.
	 * @param bool              $validate_cert Whether to verify the server's TLS certificate.
	 * @param ?BH_Email_Account $existing      The account already saved for the address, if any.
	 */
	public function __construct(
		public string $email_address,
		public string $display_name,
		public string $server,
		public string $username,
		public string $password,
		public string $encryption,
		public bool $validate_cert,
		public ?BH_Email_Account $existing,
	) {
	}
}

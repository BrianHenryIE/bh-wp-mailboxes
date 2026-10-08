<?php
/**
 * Named Email_Account_Settings_Interface for the Demo mailbox's accounts.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

/**
 * A Demo account: its address and display name; the defaults trait supplies everything else, and demo
 * emails are kept indefinitely.
 */
class Demo_Account_Settings implements Email_Account_Settings_Interface {
	use Email_Account_Settings_Defaults_Trait;

	/**
	 * Constructor.
	 *
	 * @param string $email_address The account's email address.
	 * @param string $display_name  The account's display name.
	 */
	public function __construct(
		protected string $email_address,
		protected string $display_name,
	) {
	}

	/**
	 * The account's email address.
	 */
	public function get_account_email_address(): string {
		return $this->email_address;
	}

	/**
	 * The account's display name.
	 */
	public function get_account_display_friendly_name(): string {
		return $this->display_name;
	}

	/**
	 * Demo emails are never purged.
	 */
	public function get_delete_emails_days(): ?int {
		return null;
	}
}

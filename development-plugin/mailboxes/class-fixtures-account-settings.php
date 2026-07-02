<?php
/**
 * Named Email_Account_Settings_Interface for the fixtures demo mailbox account.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

/**
 * The fixtures demo account (`fixture@example.com`); all other settings come from the defaults trait.
 */
class Fixtures_Account_Settings implements Email_Account_Settings_Interface {
	use Email_Account_Settings_Defaults_Trait;

	/**
	 * The fixtures account email address.
	 */
	public function get_account_email_address(): string {
		return 'fixture@example.com';
	}
}

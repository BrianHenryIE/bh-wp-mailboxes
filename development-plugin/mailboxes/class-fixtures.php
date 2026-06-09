<?php
/**
 * A dummy inbox with some fake emails in it.
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

class Fixtures {

	public function get_mailbox_settings(): ?Email_Account_Settings_Interface {
		return null;
	}

	// public function load_fixtures(): void {}
}

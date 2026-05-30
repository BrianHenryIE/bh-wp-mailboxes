<?php
/**
 * A dummy inbox with some fake emails in it.
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;

class Fixtures {

	public function get_mailbox_settings(): ?Mailbox_Settings_Interface {
		return null;
	}

	// public function load_fixtures(): void {}
}

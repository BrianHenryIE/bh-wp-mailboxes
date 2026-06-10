<?php
/**
 * A dummy inbox with some fake emails in it.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

/**
 * Provides stub/fixture mailbox settings for testing.
 */
class Fixtures {

	/**
	 * Returns the fixture mailbox settings, or null when no fixtures are configured.
	 */
	public function get_mailbox_settings(): ?Email_Account_Settings_Interface {
		return null;
	}

	// public function load_fixtures(): void {} // TODO: implement.
}

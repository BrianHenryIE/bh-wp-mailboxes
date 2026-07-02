<?php
/**
 * Unit tests for the development plugin's parameterized mailbox settings.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Mailbox_Settings
 */
class Mailbox_Settings_Unit_Test extends Unit_Testcase {

	/**
	 * The constructor arguments are returned by their getters.
	 *
	 * @covers ::__construct
	 * @covers ::get_plugin_slug
	 * @covers ::get_emails_cpt_friendly_name
	 * @covers ::get_email_accounts_cpt_friendly_name
	 */
	public function test_getters_return_constructor_arguments(): void {

		$settings = new Mailbox_Settings( 'development-plugin', 'IMAP Email ENV', 'IMAP Accounts ENV' );

		$this->assertSame( 'development-plugin', $settings->get_plugin_slug() );
		$this->assertSame( 'IMAP Email ENV', $settings->get_emails_cpt_friendly_name() );
		$this->assertSame( 'IMAP Accounts ENV', $settings->get_email_accounts_cpt_friendly_name() );
	}

	/**
	 * The defaults trait derives the CLI base from the plugin slug (no sanitisation involved).
	 *
	 * @covers ::get_plugin_slug
	 */
	public function test_cli_base_defaults_to_plugin_slug(): void {

		$settings = new Mailbox_Settings( 'my-mailbox', 'Emails', 'Accounts' );

		$this->assertSame( 'my-mailbox', $settings->get_cli_base() );
	}
}

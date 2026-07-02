<?php
/**
 * Unit tests for the fixtures demo account settings.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Fixtures_Account_Settings
 */
class Fixtures_Account_Settings_Unit_Test extends Unit_Testcase {

	/**
	 * The fixtures account uses the demo email address.
	 *
	 * @covers ::get_account_email_address
	 */
	public function test_get_account_email_address(): void {
		$this->assertSame( 'fixture@example.com', new Fixtures_Account_Settings()->get_account_email_address() );
	}
}

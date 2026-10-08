<?php
/**
 * Unit tests for the Demo mailbox's account settings.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Demo_Account_Settings
 */
class Demo_Account_Settings_Unit_Test extends Unit_Testcase {

	/**
	 * The address and display name are the ones given, and demo emails are never purged.
	 *
	 * @covers ::__construct
	 * @covers ::get_account_email_address
	 * @covers ::get_account_display_friendly_name
	 * @covers ::get_delete_emails_days
	 */
	public function test_settings(): void {
		$sut = new Demo_Account_Settings( 'support@example-shop.test', 'Support (fetched)' );

		$this->assertSame( 'support@example-shop.test', $sut->get_account_email_address() );
		$this->assertSame( 'Support (fetched)', $sut->get_account_display_friendly_name() );
		$this->assertNull( $sut->get_delete_emails_days() );
	}
}

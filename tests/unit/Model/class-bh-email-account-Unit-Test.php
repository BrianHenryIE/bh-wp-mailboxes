<?php
/**
 * Tests for BH_Email_Account model.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\BH_Email_Account
 */
class BH_Email_Account_Unit_Test extends Unit_Testcase {

	/**
	 * An account with status 'active' must report itself as active.
	 *
	 * @covers ::is_active
	 */
	public function test_is_active_returns_true_for_active_status(): void {

		$sut = BH_Email_Account_Fixture::make( status: 'active' );

		$this->assertTrue( $sut->is_active() );
	}

	/**
	 * An account with status 'inactive' must not report itself as active.
	 *
	 * @covers ::is_active
	 */
	public function test_is_active_returns_false_for_inactive_status(): void {

		$sut = BH_Email_Account_Fixture::make( status: 'inactive' );

		$this->assertFalse( $sut->is_active() );
	}

	/**
	 * A null after_download_email_action must default to 'nothing'.
	 *
	 * @covers ::after_download_email_action
	 */
	public function test_after_download_email_action_returns_nothing_when_null(): void {

		$sut = BH_Email_Account_Fixture::make( after_download_email_action: null );

		$this->assertSame( 'nothing', $sut->after_download_email_action() );
	}

	/**
	 * A configured action value must be returned as-is.
	 *
	 * @covers ::after_download_email_action
	 */
	public function test_after_download_email_action_returns_configured_value(): void {

		$sut = BH_Email_Account_Fixture::make( after_download_email_action: 'mark_read' );

		$this->assertSame( 'mark_read', $sut->after_download_email_action() );
	}

	/**
	 * All public getter methods must return what was passed to the constructor.
	 *
	 * @covers ::get_post_id
	 * @covers ::get_account_email_address
	 * @covers ::get_account_unique_friendly_name
	 * @covers ::get_from_email_regex
	 * @covers ::get_body_identifier_regex
	 * @covers ::get_delete_emails_days
	 */
	public function test_getters_return_constructed_values(): void {

		$sut = BH_Email_Account_Fixture::make(
			post_id: 42,
			email_address: 'info@example.com',
			display_name: 'My Account',
			from_address_regex_filter: '/^sender@/',
			body_identifier_regex_filter: '/order #\d+/',
			delete_emails_after_n_days: 14,
		);

		$this->assertSame( 42, $sut->get_post_id() );
		$this->assertSame( 'info@example.com', $sut->get_account_email_address() );
		$this->assertSame( 'My Account', $sut->get_account_unique_friendly_name() );
		$this->assertSame( '/^sender@/', $sut->get_from_email_regex() );
		$this->assertSame( '/order #\d+/', $sut->get_body_identifier_regex() );
		$this->assertSame( 14, $sut->get_delete_emails_days() );
	}

	/**
	 * Null filter fields must be returned as null, not empty string.
	 *
	 * @covers ::get_from_email_regex
	 * @covers ::get_body_identifier_regex
	 * @covers ::get_delete_emails_days
	 */
	public function test_null_fields_are_returned_as_null(): void {

		$sut = BH_Email_Account_Fixture::make(
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			delete_emails_after_n_days: null,
		);

		$this->assertNull( $sut->get_from_email_regex() );
		$this->assertNull( $sut->get_body_identifier_regex() );
		$this->assertNull( $sut->get_delete_emails_days() );
	}
}

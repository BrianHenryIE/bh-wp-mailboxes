<?php
/**
 * Unit tests for the default method implementations in Email_Account_Settings_Defaults_Trait.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait
 */
class Email_Account_Settings_Defaults_Trait_Unit_Test extends Unit_Testcase {

	/**
	 * Build an account-settings object using the defaults trait.
	 *
	 * @param string $email_address The account email address.
	 */
	private function make_settings( string $email_address = 'contact@example.com' ): Email_Account_Settings_Interface {
		return new class( $email_address ) implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			/**
			 * @param string $email_address The account email address.
			 */
			public function __construct( private string $email_address ) {}

			public function get_account_email_address(): string {
				return $this->email_address;
			}
		};
	}

	/**
	 * The display name defaults to the account email address.
	 *
	 * @covers ::get_account_display_friendly_name
	 */
	public function test_display_name_defaults_to_email_address(): void {
		$this->assertSame( 'contact@example.com', $this->make_settings()->get_account_display_friendly_name() );
	}

	/**
	 * The default post-download action is "nothing"; the default retention is 7 days.
	 *
	 * @covers ::after_download_remote_email_action
	 * @covers ::get_delete_emails_days
	 */
	public function test_download_action_and_retention_defaults(): void {
		$settings = $this->make_settings();

		$this->assertSame( 'nothing', $settings->after_download_remote_email_action() );
		$this->assertSame( 7, $settings->get_delete_emails_days() );
	}

	/**
	 * The from/body regex filters default to null (no filtering).
	 *
	 * @covers ::get_from_email_regex
	 * @covers ::get_body_identifier_regex
	 */
	public function test_regex_filters_default_to_null(): void {
		$settings = $this->make_settings();

		$this->assertNull( $settings->get_from_email_regex() );
		$this->assertNull( $settings->get_body_identifier_regex() );
	}

	/**
	 * An account is active by default; remote mark-read and delete are off by default (conservative).
	 *
	 * @covers ::is_active
	 * @covers ::can_mark_read
	 * @covers ::can_delete_on_server
	 */
	public function test_capability_and_active_defaults(): void {
		$settings = $this->make_settings();

		$this->assertTrue( $settings->is_active() );
		$this->assertFalse( $settings->can_mark_read() );
		$this->assertFalse( $settings->can_delete_on_server() );
	}
}

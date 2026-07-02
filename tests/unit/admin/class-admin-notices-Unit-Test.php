<?php
/**
 * Unit tests for Admin_Notices — the per-account "could not connect" notice.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTimeImmutable;
use Mockery;
use stdClass;
use WP_Mock;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Admin_Notices
 */
class Admin_Notices_Unit_Test extends Unit_Testcase {

	/**
	 * The emails CPT name used across these tests.
	 *
	 * @var string
	 */
	private string $post_type = 'fixtures_email';

	/**
	 * Pretend we are on an `edit.php` screen for the given post type, and passthru esc_html.
	 *
	 * @param string $post_type The screen's post type.
	 */
	private function on_screen( string $post_type ): void {
		$screen            = new stdClass();
		$screen->post_type = $post_type;
		$screen->base      = 'edit';
		WP_Mock::userFunction( 'get_current_screen', array( 'return' => $screen ) );
		WP_Mock::passthruFunction( 'esc_html' );
	}

	/**
	 * Build the SUT with a mocked API returning the given accounts.
	 *
	 * @param array<int, \BrianHenryIE\WP_Mailboxes\BH_Email_Account> $accounts The accounts to enumerate.
	 */
	private function get_sut( array $accounts ): Admin_Notices {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );

		$api = Mockery::mock( API_Interface::class );
		$api->allows( 'get_email_accounts' )->andReturn( $accounts );

		return new Admin_Notices( $api, $settings, $this->logger );
	}

	/**
	 * A dismissible error notice naming the account is shown when the last attempt failed.
	 *
	 * @covers ::display
	 */
	public function test_notice_shown_when_last_attempt_failed(): void {
		$this->on_screen( $this->post_type );

		$account = BH_Email_Account_Fixture::make(
			post_id: 12,
			email_address: 'broken@example.com',
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( 'now' ),
		);

		ob_start();
		$this->get_sut( array( $account ) )->display();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $out );
		$this->assertStringContainsString( 'is-dismissible', $out );
		$this->assertStringContainsString( 'bh-mailboxes-auth-failure', $out );
		$this->assertStringContainsString( 'broken@example.com', $out );
		$this->assertStringContainsString( 'data-account-id="12"', $out );
	}

	/**
	 * The notice is gone once a later success is recorded (self-clearing).
	 *
	 * @covers ::display
	 */
	public function test_no_notice_after_a_later_success(): void {
		$this->on_screen( $this->post_type );

		$account = BH_Email_Account_Fixture::make(
			email_address: 'recovered@example.com',
			last_successful_login_time: new DateTimeImmutable( 'now' ),
			last_failed_login_time: new DateTimeImmutable( '-1 hour' ),
		);

		ob_start();
		$this->get_sut( array( $account ) )->display();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * No notice when the account has never failed.
	 *
	 * @covers ::display
	 */
	public function test_no_notice_when_never_failed(): void {
		$this->on_screen( $this->post_type );

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: new DateTimeImmutable( 'now' ),
			last_failed_login_time: null,
		);

		ob_start();
		$this->get_sut( array( $account ) )->display();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Nothing is rendered away from the emails list screen.
	 *
	 * @covers ::display
	 */
	public function test_not_rendered_off_the_emails_list_screen(): void {
		$this->on_screen( 'post' );

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( 'now' ),
		);

		ob_start();
		$this->get_sut( array( $account ) )->display();

		$this->assertSame( '', (string) ob_get_clean() );
	}
}

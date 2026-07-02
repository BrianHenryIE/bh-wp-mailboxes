<?php
/**
 * Unit tests for Admin_Notices — the per-account "could not connect" notice built on wptrt/admin-notices.
 *
 * The class no longer prints markup; it registers notices with a WPTRT\AdminNotices\Notices instance. These
 * tests inject a mock Notices and assert the orchestration: which accounts get a notice, the per-failure id,
 * the filtered message, and that dismissed notices are not re-added.
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
use WPTRT\AdminNotices\Notices;

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
	 * Stub the WordPress functions the display path calls (each exactly once, so per-test values stick).
	 *
	 * @param ?string $screen_id The current screen id (defaults to the emails list screen).
	 * @param string  $user_meta The dismissal user-meta value ('' = not dismissed).
	 */
	private function arrange_emails_screen( ?string $screen_id = null, string $user_meta = '' ): void {
		WP_Mock::userFunction( 'wp_doing_ajax', array( 'return' => false ) );
		$screen     = new stdClass();
		$screen->id = $screen_id ?? 'edit-' . $this->post_type;
		WP_Mock::userFunction( 'get_current_screen', array( 'return' => $screen ) );
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'get_user_meta', array( 'return' => $user_meta ) );
		// apply_filters passes the message through unchanged unless a test registers an onFilter reply.
	}

	/**
	 * Build the SUT with a mocked API + settings and the given (mock) Notices.
	 *
	 * @param array<int, \BrianHenryIE\WP_Mailboxes\BH_Email_Account> $accounts The accounts to enumerate.
	 * @param Notices                                                 $notices  The mock notices registry.
	 */
	private function get_sut( array $accounts, Notices $notices ): Admin_Notices {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'bh-wp-mailboxes' );

		$api = Mockery::mock( API_Interface::class );
		$api->allows( 'get_email_accounts' )->andReturn( $accounts );

		return new Admin_Notices( $api, $settings, $this->logger, $notices );
	}

	/**
	 * A Notices mock that records every add() call into $captured and every boot() into $booted.
	 *
	 * @param array<int, array<int, mixed>> $captured Filled with the args of each add() call.
	 * @param bool                          $booted   Set true when boot() is called.
	 */
	private function recording_notices( array &$captured, bool &$booted ): Notices {
		$notices = Mockery::mock( Notices::class );
		$notices->shouldReceive( 'add' )->andReturnUsing(
			function ( ...$args ) use ( &$captured ) {
				$captured[] = $args;
			}
		);
		$notices->shouldReceive( 'boot' )->andReturnUsing(
			function () use ( &$booted ) {
				$booted = true;
			}
		);
		return $notices;
	}

	/**
	 * A failing account gets one notice, with a per-failure id and a message naming the account.
	 *
	 * @covers ::render_on_emails_screen
	 * @covers ::add_failure_notices
	 */
	public function test_notice_added_for_failing_account(): void {
		$this->arrange_emails_screen();

		$account = BH_Email_Account_Fixture::make(
			post_id: 12,
			email_address: 'broken@example.com',
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( '@1751000000' ),
		);

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->render_on_emails_screen();

		$this->assertCount( 1, $captured );
		[ $id, $title, $message, $options ] = $captured[0];
		$this->assertSame( 'bh-wp-mailboxes-auth-failure-12-1751000000', $id );
		$this->assertSame( '', $title );
		$this->assertStringContainsString( 'broken@example.com', $message );
		$this->assertSame( 'error', $options['type'] );
		$this->assertSame( 'user', $options['scope'] );
		$this->assertSame( array( 'edit-fixtures_email' ), $options['screens'] );
		$this->assertTrue( $booted );
	}

	/**
	 * No notice once a later success outranks the failure.
	 *
	 * @covers ::add_failure_notices
	 */
	public function test_no_notice_after_a_later_success(): void {
		$this->arrange_emails_screen();

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: new DateTimeImmutable( 'now' ),
			last_failed_login_time: new DateTimeImmutable( '-1 hour' ),
		);

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->render_on_emails_screen();

		$this->assertSame( array(), $captured );
	}

	/**
	 * No notice when the account has never failed.
	 *
	 * @covers ::add_failure_notices
	 */
	public function test_no_notice_when_never_failed(): void {
		$this->arrange_emails_screen();

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: new DateTimeImmutable( 'now' ),
			last_failed_login_time: null,
		);

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->render_on_emails_screen();

		$this->assertSame( array(), $captured );
	}

	/**
	 * Nothing is registered (or booted) away from the emails list screen.
	 *
	 * @covers ::render_on_emails_screen
	 */
	public function test_not_rendered_off_the_emails_list_screen(): void {
		$this->arrange_emails_screen( screen_id: 'edit-post' );

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( 'now' ),
		);

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->render_on_emails_screen();

		$this->assertSame( array(), $captured );
		$this->assertFalse( $booted );
	}

	/**
	 * An already-dismissed notice is not re-added (avoids the wptrt v1.0.4 dismiss-script error).
	 *
	 * @covers ::is_dismissed
	 */
	public function test_dismissed_notice_is_not_readded(): void {
		$this->arrange_emails_screen( user_meta: '1' );

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( 'now' ),
		);

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->render_on_emails_screen();

		$this->assertSame( array(), $captured );
	}

	/**
	 * The message is passed through the `bh_wp_mailboxes_auth_failure_notice_message` filter.
	 *
	 * @covers ::add_failure_notices
	 */
	public function test_message_filter_is_applied(): void {
		$this->arrange_emails_screen();

		$account = BH_Email_Account_Fixture::make(
			email_address: 'filtered@example.com',
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( 'now' ),
		);

		// The default message the filter receives ( __() is passed through unchanged by the test harness).
		$default  = sprintf(
			'bh-wp-mailboxes could not connect to the account “%s” on the last attempt. Please check the connection settings.',
			'filtered@example.com'
		);
		$filtered = 'Connection failed — <a href="/settings">open settings</a>.';
		WP_Mock::onFilter( 'bh_wp_mailboxes_auth_failure_notice_message' )
			->with( $default, $account, 'bh-wp-mailboxes' )
			->reply( $filtered );

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->render_on_emails_screen();

		$this->assertCount( 1, $captured );
		$this->assertSame( $filtered, $captured[0][2] );
	}

	/**
	 * On the wptrt dismiss AJAX request the notices are re-registered (so the handler matches) but not booted.
	 *
	 * @covers ::register_dismiss_handler
	 */
	public function test_notices_registered_on_dismiss_ajax(): void {
		WP_Mock::userFunction( 'wp_doing_ajax', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_unslash', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'get_user_meta', array( 'return' => '' ) );
		$_REQUEST['action'] = 'wptrt_dismiss_notice';

		$account = BH_Email_Account_Fixture::make(
			last_successful_login_time: null,
			last_failed_login_time: new DateTimeImmutable( 'now' ),
		);

		$captured = array();
		$booted   = false;
		$this->get_sut( array( $account ), $this->recording_notices( $captured, $booted ) )->register_dismiss_handler();

		unset( $_REQUEST['action'] );

		$this->assertCount( 1, $captured );
		$this->assertFalse( $booted );
	}
}

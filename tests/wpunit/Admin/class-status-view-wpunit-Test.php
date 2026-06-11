<?php
/**
 * WPUnit tests for Status_View.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Status_View
 */
class Status_View_WPUnit_Test extends WPUnit_Testcase {

	/** @var string CPT slug used throughout this suite. */
	private string $post_type = 'test_sv_emails';

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( $this->post_type ) ) {
			register_post_type( $this->post_type, array( 'public' => false ) );
		}
		set_current_screen( 'edit-' . $this->post_type );
	}

	public function tearDown(): void {
		set_current_screen( 'front' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_repository(): Email_WP_Post_Repository {
		return new Email_WP_Post_Repository(
			$this->post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger,
		);
	}

	/**
	 * Build a minimal BH_Email_Account stub.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function make_account( array $overrides = array() ): BH_Email_Account {
		return new BH_Email_Account(
			post_id: $overrides['post_id'] ?? 1,
			post_type: $overrides['post_type'] ?? $this->post_type,
			status: $overrides['status'] ?? 'active',
			provider_type_class: $overrides['provider_type_class'] ?? 'SomeProvider',
			email_address: $overrides['email_address'] ?? 'test@example.com',
			display_name: $overrides['display_name'] ?? 'Test Account',
			from_address_regex_filter: $overrides['from_address_regex_filter'] ?? null,
			body_identifier_regex_filter: $overrides['body_identifier_regex_filter'] ?? null,
			after_download_email_action: $overrides['after_download_email_action'] ?? null,
			delete_emails_after_n_days: $overrides['delete_emails_after_n_days'] ?? null,
			last_successful_login_time: $overrides['last_successful_login_time'] ?? null,
			last_failed_login_time: $overrides['last_failed_login_time'] ?? null,
		);
	}

	private function make_sut(
		API_Interface $api,
		?Email_WP_Post_Repository $repo = null,
	): Status_View {
		/** @var BH_WP_Mailboxes_Settings_Interface $settings */
		$settings = \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );

		return new Status_View(
			$api,
			$settings,
			$repo ?? $this->make_repository(),
			$this->logger,
		);
	}

	private function capture_display( Status_View $sut, string $which = 'top' ): string {
		ob_start();
		$sut->display( $which );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Output guarding
	// -------------------------------------------------------------------------

	/**
	 * Nothing should be printed when the current screen has a different post type.
	 *
	 * @covers ::display
	 */
	public function test_display_outputs_nothing_for_wrong_post_type(): void {
		set_current_screen( 'edit-post' );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->shouldNotReceive( 'get_email_accounts' );

		$html = $this->capture_display( $this->make_sut( $api ) );
		$this->assertSame( '', $html );
	}

	/**
	 * Nothing should be printed for the 'bottom' position.
	 *
	 * @covers ::display
	 */
	public function test_display_outputs_nothing_for_bottom_position(): void {
		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->shouldNotReceive( 'get_email_accounts' );

		$html = $this->capture_display( $this->make_sut( $api ), 'bottom' );
		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// No accounts
	// -------------------------------------------------------------------------

	/**
	 * When no accounts are configured, a "No accounts configured" message is shown.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_no_accounts_message_when_empty(): void {
		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'id="bh-mailboxes-status"', $html );
		$this->assertStringContainsString( 'No accounts configured', $html );
		$this->assertStringNotContainsString( '<div class="bh-mailboxes-account-card">', $html );
	}

	// -------------------------------------------------------------------------
	// Account rows
	// -------------------------------------------------------------------------

	/**
	 * The account's email address appears in its card.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_account_email_address(): void {
		$account = $this->make_account( array( 'email_address' => 'inbox@example.com' ) );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'inbox@example.com', $html );
		$this->assertStringContainsString( '<div class="bh-mailboxes-account-card">', $html );
	}

	/**
	 * "Never" is shown for last-fetched when the account has never been fetched.
	 *
	 * @covers ::display
	 * @covers ::format_time
	 */
	public function test_display_shows_never_when_last_fetched_is_null(): void {
		$account = $this->make_account( array( 'last_successful_login_time' => null ) );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Never', $html );
	}

	/**
	 * "Never" is shown for last-failure when the account has no recorded failure.
	 *
	 * @covers ::display
	 * @covers ::format_time
	 */
	public function test_display_shows_never_when_last_failure_is_null(): void {
		$account = $this->make_account( array( 'last_failed_login_time' => null ) );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Never', $html );
	}

	/**
	 * A relative time string is shown when last-fetched is set.
	 *
	 * @covers ::display
	 * @covers ::format_time
	 */
	public function test_display_shows_relative_time_when_last_fetched_is_set(): void {
		$one_hour_ago = new DateTimeImmutable( '-1 hour', new DateTimeZone( 'UTC' ) );
		$account      = $this->make_account( array( 'last_successful_login_time' => $one_hour_ago ) );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'ago', $html );
		$this->assertStringContainsString( 'hour', $html );
	}

	/**
	 * The email count for the account is shown in its card.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_email_count(): void {
		$account_email = 'inbox@example.com';

		// Save two fixture emails for this account address so the count is 2.
		foreach ( range( 1, 2 ) as $i ) {
			$this->factory()->post->create(
				array(
					'post_type'   => $this->post_type,
					'post_status' => 'publish',
					'post_parent' => 321,
				)
			);
		}

		$account = $this->make_account(
			array(
				'email_address' => $account_email,
				'post_id'       => 321,
			)
		);

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( '>2<', $html );
	}

	/**
	 * "Active" label is rendered for an active account.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_active_label_for_active_account(): void {
		$account = $this->make_account( array( 'status' => 'active' ) );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Active', $html );
	}

	/**
	 * "Inactive" label is rendered for an inactive account.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_inactive_label_for_inactive_account(): void {
		$account = $this->make_account( array( 'status' => 'inactive' ) );

		/** @var API_Interface $api */
		$api = \Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Inactive', $html );
	}
}

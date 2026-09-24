<?php
/**
 * WPUnit tests for Status_View (the accounts table above the emails list).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;

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

	/**
	 * Instantiate a `Email_WP_Post_Repository` object.
	 */
	private function make_repository(): Email_WP_Post_Repository {
		return new Email_WP_Post_Repository(
			$this->post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger,
		);
	}

	private function make_sut(
		API_Interface $api,
		?Email_WP_Post_Repository $repo = null,
	): Status_View {
		/** @var BH_WP_Mailboxes_Settings_Interface $settings */
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $this->post_type . '_accounts' );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );

		// These tests cover the rendering; the capability gate has its own tests (Capability_Aware_UI_WPUnit_Test).
		$capabilities = Mockery::mock( Mailbox_Capabilities::class );
		$capabilities->allows( 'current_user_can_manage_email_accounts' )->andReturn( true );

		return new Status_View(
			$api,
			$settings,
			$repo ?? $this->make_repository(),
			$this->logger,
			new Email_Account_Modal( $settings, $capabilities ),
			$capabilities,
		);
	}

	/**
	 * A fetch-capable connection, so rows render "Check now", last fetched/failure times and Delete.
	 */
	private function fetching_connection(): Supports_Fetching {
		return Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
	}

	/**
	 * An editable IMAP-style connection (fetches and requires credentials), so the row carries the edit-form data.
	 */
	private function imap_connection(): Supports_Fetching {
		return Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class, Requires_Credentials::class );
	}

	private function capture_display( Status_View $sut ): string {
		ob_start();
		$sut->display();
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Output guarding
	// -------------------------------------------------------------------------

	/**
	 * Nothing should be printed when the current screen has a different post type.
	 *
	 * @covers ::__construct
	 * @covers ::display
	 */
	public function test_display_outputs_nothing_for_wrong_post_type(): void {
		set_current_screen( 'edit-post' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->shouldNotReceive( 'get_email_accounts' );

		$html = $this->capture_display( $this->make_sut( $api ) );
		$this->assertSame( '', $html );
	}

	/**
	 * Nothing should be printed on a single-post screen (non-list base).
	 *
	 * @covers ::display
	 */
	public function test_display_outputs_nothing_on_single_post_screen(): void {
		set_current_screen( 'post' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->shouldNotReceive( 'get_email_accounts' );

		$html = $this->capture_display( $this->make_sut( $api ) );
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
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'id="bh-mailboxes-status"', $html );
		$this->assertStringContainsString( 'No accounts configured', $html );
		$this->assertStringNotContainsString( 'class="bh-mailboxes-account"', $html );
		$this->assertStringContainsString( 'bh-account-add', $html, 'The "Add account" button is shown even with no accounts.' );
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
		$account = BH_Email_Account_Fixture::make( email_address: 'inbox@example.com' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'inbox@example.com', $html );
		$this->assertStringContainsString( 'class="bh-mailboxes-account"', $html );
		$this->assertStringContainsString( 'data-email-address="inbox@example.com"', $html );
	}

	/**
	 * The row carries the saved certificate-validation choice for the edit form to pre-fill; an
	 * account without credentials reads as validating (the form's default).
	 *
	 * @covers ::display
	 */
	public function test_display_row_carries_validate_cert(): void {
		$account = BH_Email_Account_Fixture::make( email_address: 'inbox@example.com', connection_type_class: ImapEngine_Imap_Email_Connection::class );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->imap_connection() );
		$api->allows( 'get_account_credentials' )->andReturn( new Imap_Credentials( 'imap.internal', 'user', 'pw', 'TLS', false ) );

		$this->assertStringContainsString( 'data-validate-cert="0"', $this->capture_display( $this->make_sut( $api ) ) );

		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->imap_connection() );
		$api->allows( 'get_account_credentials' )->andReturn( null );

		$this->assertStringContainsString( 'data-validate-cert="1"', $this->capture_display( $this->make_sut( $api ) ) );
	}

	/**
	 * "Never" is shown for last-fetched when the account has never been fetched.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_never_when_last_fetched_is_null(): void {
		$account = BH_Email_Account_Fixture::make( last_successful_login_time: null );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Never', $html );
	}

	/**
	 * "Never" is shown for last-failure when the account has no recorded failure.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_never_when_last_failure_is_null(): void {
		$account = BH_Email_Account_Fixture::make( last_failed_login_time: null );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Never', $html );
	}

	/**
	 * A relative time string is shown when last-fetched is set.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_relative_time_when_last_fetched_is_set(): void {
		$one_hour_ago = new DateTimeImmutable( '-1 hour', new DateTimeZone( 'UTC' ) );
		$account      = BH_Email_Account_Fixture::make( last_successful_login_time: $one_hour_ago );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

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

		$account = BH_Email_Account_Fixture::make(
			post_id: 321,
			email_address: $account_email,
		);

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( '>2<', $html );
	}

	/**
	 * "Active" label is rendered for an active account.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_active_label_for_active_account(): void {
		$account = BH_Email_Account_Fixture::make( local_status: 'bh_email_ac_active' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Active', $html );
	}

	/**
	 * "Inactive" label is rendered for an inactive account.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_inactive_label_for_inactive_account(): void {
		$account = BH_Email_Account_Fixture::make( local_status: 'bh_email_ac_inactive' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'Inactive', $html );
	}

	/**
	 * A receive-only account (its connection cannot fetch) has nothing to check: "N/A" replaces the
	 * times, and it can be disabled but not checked or deleted.
	 *
	 * @covers ::display
	 * @covers ::render_table
	 */
	public function test_display_receive_only_account_shows_not_applicable(): void {
		$account = BH_Email_Account_Fixture::make( email_address: 'ingress@example.com' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( Mockery::mock( Email_Connection_Interface::class ) );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'N/A', $html );
		$this->assertStringNotContainsString( 'Never', $html );
		$this->assertStringNotContainsString( 'bh-check-account', $html );
		$this->assertStringNotContainsString( 'bh-account-delete', $html );
		$this->assertStringContainsString( 'bh-account-toggle', $html );
	}

	/**
	 * A fetch-capable account offers "Check now", "Check since…" (with its default date), and Delete; the check-since dialog is printed.
	 *
	 * @covers ::display
	 * @covers ::render_table
	 */
	public function test_display_fetching_account_offers_check_now_and_delete(): void {
		$account = BH_Email_Account_Fixture::make( post_id: 77 );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'class="bh-check-account" data-account-id="77"', $html );
		$this->assertStringContainsString( 'class="bh-fetch-since-toggle" data-account-id="77" data-since-value="', $html );
		$this->assertStringNotContainsString( 'bh-fetch-since-input" data-account-id', $html, 'The date input lives in the dialog, not in the row.' );
		$this->assertStringContainsString( '<dialog id="bh-mailboxes-fetch-since"', $html );
		$this->assertStringContainsString( '<div class="row-actions"><span class=\'toggle\'>', $html, 'Enable/disable, edit, delete are row actions on the account column.' );
		$this->assertStringContainsString( 'bh-account-delete', $html );
		$this->assertStringNotContainsString( 'column-actions', $html );
		$this->assertStringContainsString( 'id="bh-mailboxes-account-dialog"', $html, 'The add/edit modal is printed.' );
	}

	/**
	 * The table is a WP_List_Table keyed to the accounts CPT screen, without a checkbox column, bulk
	 * actions or table nav, and without core's `id="the-list"` tbody (that is the emails list table's).
	 *
	 * @covers ::render_table
	 * @covers \BrianHenryIE\WP_Mailboxes\Admin\Email_Accounts_List_Table
	 */
	public function test_display_renders_a_list_table_without_checkbox_or_bulk_actions(): void {
		$account = BH_Email_Account_Fixture::make();

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( 'class="wp-list-table widefat striped bh-mailboxes-accounts"', $html );
		$this->assertStringContainsString( "class='manage-column column-account", $html );
		// No bulk-select column (the modal printed alongside the table has its own checkbox).
		$this->assertStringNotContainsString( 'check-column', $html );
		$this->assertStringNotContainsString( 'bulkactions', $html );
		$this->assertStringNotContainsString( 'class="tablenav', $html );
		$this->assertStringNotContainsString( 'id="the-list"', $html );
		$this->assertStringContainsString( 'class="bh-mailboxes-accounts__rows"', $html );
		$this->assertFalse( has_filter( 'manage_edit-' . $this->post_type . '_columns' ), 'The emails screen columns filter must not be touched.' );
	}

	/**
	 * Emails still in `bh_email_new` are called out beside the count; nothing is appended when there are none.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_unprocessed_count_only_when_nonzero(): void {
		foreach ( array( 'bh_email_new', 'bh_email_new', 'bh_email_processed' ) as $status ) {
			$this->factory()->post->create(
				array(
					'post_type'   => $this->post_type,
					'post_status' => $status,
					'post_parent' => 322,
				)
			);
		}
		$this->factory()->post->create(
			array(
				'post_type'   => $this->post_type,
				'post_status' => 'bh_email_processed',
				'post_parent' => 323,
			)
		);

		$with_new    = BH_Email_Account_Fixture::make( post_id: 322, email_address: 'new@example.com' );
		$without_new = BH_Email_Account_Fixture::make( post_id: 323, email_address: 'done@example.com' );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $with_new, $without_new ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( '<span data-field="email-count">3</span><span data-field="email-count-new" class="bh-mailboxes-muted" title="Not yet processed by a plugin."> (2 new)</span>', $html );
		$this->assertStringContainsString( '<span data-field="email-count">1</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'data-field="email-count-new"' ), 'Only the account with new emails gets the suffix.' );
	}

	/**
	 * The lifetime "N fetched · N ignored" line appears under the count with a full-breakdown tooltip, and
	 * only for accounts that have fetched something.
	 *
	 * @covers ::display
	 */
	public function test_display_shows_lifetime_totals_only_when_fetched(): void {
		foreach ( range( 1, 45 ) as $i ) {
			$this->factory()->post->create(
				array(
					'post_type'   => $this->post_type,
					'post_status' => 'bh_email_processed',
					'post_parent' => 324,
				)
			);
		}

		$fetched = BH_Email_Account_Fixture::make( post_id: 324, email_address: 'busy@example.com', total_emails_downloaded_count: 120, total_emails_saved_count: 115 );
		$fresh   = BH_Email_Account_Fixture::make( post_id: 325, email_address: 'fresh@example.com', total_emails_downloaded_count: 0, total_emails_saved_count: 0 );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $fetched, $fresh ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( '<span data-field="lifetime" class="bh-mailboxes-account__lifetime bh-mailboxes-muted" data-fetched="120" data-saved="115" title="120 fetched, 5 ignored by the account&#039;s filters, 115 saved, 70 since removed.">120 fetched · 5 ignored</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'data-field="lifetime"' ), 'The fresh account has no lifetime line.' );
	}

	/**
	 * When nothing was ignored, the lifetime line says only "N fetched".
	 *
	 * @covers ::display
	 */
	public function test_display_omits_ignored_when_zero(): void {
		$account = BH_Email_Account_Fixture::make( post_id: 326, total_emails_downloaded_count: 7, total_emails_saved_count: 7 );

		/** @var API_Interface $api */
		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'get_email_accounts' )->once()->andReturn( array( $account ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $this->fetching_connection() );

		$html = $this->capture_display( $this->make_sut( $api ) );

		$this->assertStringContainsString( '>7 fetched</span>', $html );
		$this->assertStringNotContainsString( 'ignored</span>', $html );
		$this->assertStringContainsString( '7 fetched, 0 ignored by the account&#039;s filters, 7 saved, 7 since removed.', $html );
	}
}

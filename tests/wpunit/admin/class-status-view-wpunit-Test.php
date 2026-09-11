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

		return new Status_View(
			$api,
			$settings,
			$repo ?? $this->make_repository(),
			$this->logger,
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
	 * A fetch-capable account offers "Check now", the set-fetch-since date input, and Delete.
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
		$this->assertStringContainsString( 'bh-fetch-since-input', $html );
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
}

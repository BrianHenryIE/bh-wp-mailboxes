<?php
/**
 * WPUnit tests proving each admin control is rendered only for users who may use it.
 *
 * Every render site is run as three users: a subscriber (the control is absent), an administrator
 * (present), and a subscriber granted the mailbox's capabilities through the consumer-facing
 * `bh_wp_mailboxes_required_capability` filter (present, proving the extension point reaches the UI).
 * The real post types and the real capability layer are registered, as the library's hooks would.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_Settings_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use WP_Post;

/**
 * @coversNothing
 */
class Capability_Aware_UI_WPUnit_Test extends WPUnit_Testcase {

	const EMAILS_CPT   = 'test_capui_emails';
	const ACCOUNTS_CPT = 'test_capui_accounts';

	/**
	 * This mailbox's settings (a mock).
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * The real capability layer under test.
	 *
	 * @var Mailbox_Capabilities
	 */
	protected Mailbox_Capabilities $capabilities;

	/**
	 * The mailbox's real emails repository.
	 *
	 * @var Email_WP_Post_Repository
	 */
	protected Email_WP_Post_Repository $repository;

	/**
	 * The `map_meta_cap` callback, removed in tearDown.
	 *
	 * @var callable
	 */
	protected $map_meta_cap;

	/**
	 * A fixture email in this mailbox, created per test.
	 *
	 * @var int
	 */
	protected int $email_id;

	public function setUp(): void {
		parent::setUp();

		$this->settings = BH_WP_Mailboxes_Settings_Fixture::make(
			plugin_slug: 'capui',
			email_cpt: self::EMAILS_CPT,
			accounts_cpt: self::ACCOUNTS_CPT,
		);
		$this->settings->allows( 'get_rest_namespace' )->andReturn( null );
		$this->settings->allows( 'get_emails_cpt_dashed' )->andReturn( 'test-capui-emails' );
		$this->settings->allows( 'get_email_accounts_cpt_dashed' )->andReturn( 'test-capui-accounts' );

		$this->capabilities = new Mailbox_Capabilities( $this->settings );
		$this->map_meta_cap = $this->capabilities->map_meta_cap( ... );
		add_filter( 'map_meta_cap', $this->map_meta_cap, 10, 4 );

		( new BH_Email_CPT( $this->settings, $this->logger ) )->register_cpt();
		( new BH_Email_CPT( $this->settings, $this->logger ) )->register_post_statuses();
		( new BH_Email_Account_CPT( $this->settings, $this->logger ) )->register_cpt();

		$this->repository = new Email_WP_Post_Repository( self::EMAILS_CPT, new BH_Email_Factory( $this->logger ), $this->logger );
		$this->email_id   = BH_Email_Fixture::make_from_file( mailbox_settings: $this->settings, repo: $this->repository )->post_id;
	}

	public function tearDown(): void {
		remove_filter( 'map_meta_cap', $this->map_meta_cap, 10 );
		remove_all_filters( Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER );
		unregister_post_type( self::EMAILS_CPT );
		unregister_post_type( self::ACCOUNTS_CPT );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Users
	// -------------------------------------------------------------------------

	/**
	 * Sign in as a new user with the role.
	 *
	 * @param string $role A WordPress role, e.g. `subscriber`.
	 */
	protected function login_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * A subscriber granted this mailbox through the consumer filter.
	 *
	 * @param ?string[] $only Grant only these mailbox capabilities (e.g. `read_post`, `edit_test_capui_emailss`); null for all.
	 */
	protected function login_as_granted_subscriber( ?array $only = null ): void {
		remove_all_filters( Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER );
		add_filter(
			Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER,
			function ( string $required, string $capability, string $post_type ) use ( $only ): string {
				if ( ! in_array( $post_type, array( self::EMAILS_CPT, self::ACCOUNTS_CPT ), true ) ) {
					return $required;
				}
				return ( is_null( $only ) || in_array( $capability, $only, true ) ) ? 'read' : $required;
			},
			10,
			3
		);
		$this->login_as( 'subscriber' );
	}

	/**
	 * A subscriber who may read and list this mailbox's emails, but not act on them.
	 */
	protected function login_as_read_only_subscriber(): void {
		$this->login_as_granted_subscriber( array( 'read_post', (string) $this->capabilities->get_list_emails_capability() ) );
	}

	// -------------------------------------------------------------------------
	// Collaborators
	// -------------------------------------------------------------------------

	/**
	 * An API whose accounts' connection supports every remote action.
	 *
	 * @param int $account_count How many accounts the mailbox has.
	 */
	protected function make_api( int $account_count = 1 ): API_Interface {
		$accounts = array();
		for ( $i = 1; $i <= $account_count; $i++ ) {
			$accounts[ "account-{$i}@example.com" ] = BH_Email_Account_Fixture::make( post_id: $i, display_name: "Account {$i}" );
		}

		$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$connection->allows( 'can_delete_on_server' )->andReturn( true );
		$connection->allows( 'can_mark_read' )->andReturn( true );
		$connection->allows( 'can_read_status' )->andReturn( true );
		$connection->allows( 'get_friendly_name' )->andReturn( 'Test' );

		/** @var API_Interface&Mockery\MockInterface $api */
		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();
		$api->allows( 'get_email_accounts' )->andReturn( $accounts );
		$api->allows( 'get_email_account_for_email' )->andReturn( reset( $accounts ) );
		$api->allows( 'get_connection_for_email_account' )->andReturn( $connection );
		$api->allows( 'get_account_credentials' )->andReturn( null );

		return $api;
	}

	protected function make_list_page( int $account_count = 1 ): Emails_List_Page {
		return new Emails_List_Page( $this->repository, $this->make_api( $account_count ), $this->settings, $this->logger, null, $this->capabilities );
	}

	protected function make_single_view(): Single_Email_View {
		return new Single_Email_View( $this->settings, $this->make_api(), $this->repository, $this->logger, $this->capabilities );
	}

	protected function make_status_view(): Status_View {
		return new Status_View( $this->make_api(), $this->settings, $this->repository, $this->logger, null, $this->capabilities );
	}

	protected function email_post(): WP_Post {
		$post = get_post( $this->email_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		return $post;
	}

	protected function on_list_screen(): void {
		set_current_screen( 'edit-' . self::EMAILS_CPT );
	}

	/**
	 * Capture what a render method prints.
	 *
	 * @param callable $render The render call.
	 */
	protected function render( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/**
	 * Let the modal print again (it prints at most once per request).
	 */
	protected function reset_modal_printed(): void {
		global $wp_actions;
		unset( $wp_actions[ Email_Account_Modal::PRINTED_ACTION ] );
	}

	// -------------------------------------------------------------------------
	// Emails list: "Check now" button
	// -------------------------------------------------------------------------

	/**
	 * Emails list: "Check now" button: check button is absent for a subscriber.
	 */
	public function test_check_button_is_absent_for_a_subscriber(): void {
		$this->login_as( 'subscriber' );
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page()->print_extra_table_controls_at_top( 'top' ) );

		$this->assertStringNotContainsString( 'check-email', $html );
	}

	/**
	 * Check button is present for an administrator.
	 */
	public function test_check_button_is_present_for_an_administrator(): void {
		$this->login_as( 'administrator' );
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page()->print_extra_table_controls_at_top( 'top' ) );

		$this->assertStringContainsString( 'id="check-email"', $html );
	}

	/**
	 * Check button is present for a granted subscriber.
	 */
	public function test_check_button_is_present_for_a_granted_subscriber(): void {
		$this->login_as_granted_subscriber();
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page()->print_extra_table_controls_at_top( 'top' ) );

		$this->assertStringContainsString( 'id="check-email"', $html );
	}

	/**
	 * "Check now" is an account action: a user who may read and act on emails but not manage accounts
	 * does not get it.
	 */
	public function test_check_button_is_absent_without_the_manage_accounts_capability(): void {
		$this->login_as_granted_subscriber( array( 'read_post', 'edit_post', 'delete_post', (string) $this->capabilities->get_list_emails_capability() ) );
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page()->print_extra_table_controls_at_top( 'top' ) );

		$this->assertStringNotContainsString( 'check-email', $html );
	}

	// -------------------------------------------------------------------------
	// Emails list: "Delete on server" row action
	// -------------------------------------------------------------------------

	/**
	 * Emails list: "Delete on server" row action: delete on server row action is absent for a read only user.
	 */
	public function test_delete_on_server_row_action_is_absent_for_a_read_only_user(): void {
		$this->login_as_read_only_subscriber();

		$actions = $this->make_list_page()->row_actions( array( 'trash' => '<a>Trash</a>' ), $this->email_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * Delete on server row action is present for an administrator.
	 */
	public function test_delete_on_server_row_action_is_present_for_an_administrator(): void {
		$this->login_as( 'administrator' );

		$actions = $this->make_list_page()->row_actions( array(), $this->email_post() );

		$this->assertArrayHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * Delete on server row action is present for a granted subscriber.
	 */
	public function test_delete_on_server_row_action_is_present_for_a_granted_subscriber(): void {
		$this->login_as_granted_subscriber();

		$actions = $this->make_list_page()->row_actions( array(), $this->email_post() );

		$this->assertArrayHasKey( 'bh_delete_on_server', $actions );
	}

	// -------------------------------------------------------------------------
	// Emails list: account filter
	// -------------------------------------------------------------------------

	/**
	 * Emails list: account filter: account filter is absent for a subscriber.
	 */
	public function test_account_filter_is_absent_for_a_subscriber(): void {
		$this->login_as( 'subscriber' );
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page( 2 )->table_filters() );

		$this->assertStringNotContainsString( 'bh_email_account', $html );
	}

	/**
	 * Account filter is present for an administrator.
	 */
	public function test_account_filter_is_present_for_an_administrator(): void {
		$this->login_as( 'administrator' );
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page( 2 )->table_filters() );

		$this->assertStringContainsString( 'name="bh_email_account"', $html );
	}

	/**
	 * Account filter is present for a granted subscriber.
	 */
	public function test_account_filter_is_present_for_a_granted_subscriber(): void {
		$this->login_as_read_only_subscriber();
		$this->on_list_screen();

		$html = $this->render( fn() => $this->make_list_page( 2 )->table_filters() );

		$this->assertStringContainsString( 'name="bh_email_account"', $html );
	}

	// -------------------------------------------------------------------------
	// Accounts table and modal
	// -------------------------------------------------------------------------

	/**
	 * Accounts table and modal: accounts table prints nothing for a subscriber.
	 */
	public function test_accounts_table_prints_nothing_for_a_subscriber(): void {
		$this->login_as_read_only_subscriber();
		$this->on_list_screen();
		$this->reset_modal_printed();

		$html = $this->render( fn() => $this->make_status_view()->display() );

		$this->assertSame( '', $html );
	}

	/**
	 * Accounts table and add button print for an administrator.
	 */
	public function test_accounts_table_and_add_button_print_for_an_administrator(): void {
		$this->login_as( 'administrator' );
		$this->on_list_screen();
		$this->reset_modal_printed();

		$html = $this->render( fn() => $this->make_status_view()->display() );

		$this->assertStringContainsString( 'id="bh-mailboxes-status"', $html );
		$this->assertStringContainsString( 'bh-account-add', $html );
		$this->assertStringContainsString( 'id="bh-mailboxes-account-dialog"', $html );
	}

	/**
	 * Accounts table and add button print for a granted subscriber.
	 */
	public function test_accounts_table_and_add_button_print_for_a_granted_subscriber(): void {
		$this->login_as_granted_subscriber();
		$this->on_list_screen();
		$this->reset_modal_printed();

		$html = $this->render( fn() => $this->make_status_view()->display() );

		$this->assertStringContainsString( 'id="bh-mailboxes-status"', $html );
		$this->assertStringContainsString( 'bh-account-add', $html );
	}

	/**
	 * A consumer embedding the modal on its own screen is gated for free.
	 */
	public function test_modal_button_and_markup_are_no_ops_for_a_subscriber(): void {
		$this->login_as( 'subscriber' );
		$this->reset_modal_printed();
		$modal = new Email_Account_Modal( $this->settings, $this->capabilities );

		$this->assertSame( '', $this->render( fn() => $modal->print_add_button() ) );
		$this->assertSame( '', $this->render( fn() => $modal->print_modal() ) );
		$this->assertSame( 0, did_action( Email_Account_Modal::PRINTED_ACTION ), 'A refused print must not count as printed.' );
	}

	/**
	 * Modal button and markup print for an administrator.
	 */
	public function test_modal_button_and_markup_print_for_an_administrator(): void {
		$this->login_as( 'administrator' );
		$this->reset_modal_printed();
		$modal = new Email_Account_Modal( $this->settings, $this->capabilities );

		$this->assertStringContainsString( 'bh-account-add', $this->render( fn() => $modal->print_add_button() ) );
		$this->assertStringContainsString( 'id="bh-mailboxes-account-dialog"', $this->render( fn() => $modal->print_modal() ) );
	}

	// -------------------------------------------------------------------------
	// Single email: local status metabox
	// -------------------------------------------------------------------------

	/**
	 * Single email: local status metabox: local status is read only without the edit capability.
	 */
	public function test_local_status_is_read_only_without_the_edit_capability(): void {
		$this->login_as_read_only_subscriber();

		$html = $this->render( fn() => $this->make_single_view()->render_local_status_metabox( $this->email_post() ) );

		$this->assertStringContainsString( 'Status:', $html );
		$this->assertStringNotContainsString( 'name="post_status"', $html, 'No radios.' );
		$this->assertStringNotContainsString( 'id="save"', $html, 'No Save button.' );
		$this->assertStringNotContainsString( 'Move to Trash', $html, 'Core offers no trash link without the delete capability.' );
	}

	/**
	 * Local status is editable for an administrator.
	 */
	public function test_local_status_is_editable_for_an_administrator(): void {
		$this->login_as( 'administrator' );

		$html = $this->render( fn() => $this->make_single_view()->render_local_status_metabox( $this->email_post() ) );

		$this->assertStringContainsString( 'name="post_status"', $html );
		$this->assertStringContainsString( 'id="save"', $html );
		$this->assertStringContainsString( 'Move to Trash', $html );
	}

	/**
	 * Local status is editable for a granted subscriber.
	 */
	public function test_local_status_is_editable_for_a_granted_subscriber(): void {
		$this->login_as_granted_subscriber();

		$html = $this->render( fn() => $this->make_single_view()->render_local_status_metabox( $this->email_post() ) );

		$this->assertStringContainsString( 'name="post_status"', $html );
		$this->assertStringContainsString( 'id="save"', $html );
	}

	// -------------------------------------------------------------------------
	// Single email: remote status metabox
	// -------------------------------------------------------------------------

	/**
	 * Single email: remote status metabox: remote status is a refreshed badge without the edit capability.
	 */
	public function test_remote_status_is_a_refreshed_badge_without_the_edit_capability(): void {
		$this->login_as_read_only_subscriber();

		$html = $this->render( fn() => $this->make_single_view()->render_remote_status_metabox( $this->email_post() ) );

		$this->assertStringNotContainsString( 'name="bh_email_remote_read"', $html, 'No radios.' );
		$this->assertStringNotContainsString( 'bh-email-remote-save', $html, 'No Update button.' );
		$this->assertStringNotContainsString( 'bh-email-delete-on-server', $html, 'No Delete on server.' );
		$this->assertStringContainsString( 'bh-email-remote-status is-loading', $html, 'The badge is refreshed from the server: the user may read.' );
	}

	/**
	 * Remote status has controls for an administrator.
	 */
	public function test_remote_status_has_controls_for_an_administrator(): void {
		$this->login_as( 'administrator' );

		$html = $this->render( fn() => $this->make_single_view()->render_remote_status_metabox( $this->email_post() ) );

		$this->assertStringContainsString( 'name="bh_email_remote_read"', $html );
		$this->assertStringContainsString( 'bh-email-remote-save', $html );
		$this->assertStringContainsString( 'bh-email-delete-on-server', $html );
	}

	/**
	 * Remote status has controls for a granted subscriber.
	 */
	public function test_remote_status_has_controls_for_a_granted_subscriber(): void {
		$this->login_as_granted_subscriber();

		$html = $this->render( fn() => $this->make_single_view()->render_remote_status_metabox( $this->email_post() ) );

		$this->assertStringContainsString( 'name="bh_email_remote_read"', $html );
		$this->assertStringContainsString( 'bh-email-remote-save', $html );
		$this->assertStringContainsString( 'bh-email-delete-on-server', $html );
	}

	// -------------------------------------------------------------------------
	// Single email: the actions the inline config advertises to the JS
	// -------------------------------------------------------------------------

	/**
	 * Single email: the actions the inline config advertises to the JS: permitted actions follow the capabilities.
	 */
	public function test_permitted_actions_follow_the_capabilities(): void {
		$view = $this->make_single_view();

		$this->login_as( 'subscriber' );
		$this->assertSame( array(), $view->get_permitted_actions( $this->email_id ) );

		$this->login_as_read_only_subscriber();
		$this->assertSame( array( 'remote-status' ), $view->get_permitted_actions( $this->email_id ) );

		$this->login_as( 'administrator' );
		$this->assertSame( array( 'remote-status', 'status', 'mark-read', 'mark-unread', 'delete-on-server' ), $view->get_permitted_actions( $this->email_id ) );
	}

	// -------------------------------------------------------------------------
	// Auth-failure notice
	// -------------------------------------------------------------------------

	/**
	 * The notice is shown to whoever may list the mailbox's emails (it used to require `edit_posts`).
	 */
	public function test_list_emails_capability_is_the_mailbox_scoped_one(): void {
		$capability = $this->capabilities->get_list_emails_capability();

		$this->assertNotNull( $capability );
		$this->assertStringContainsString( self::EMAILS_CPT, $capability );

		$this->login_as( 'subscriber' );
		$this->assertFalse( current_user_can( $capability ) );

		$this->login_as_read_only_subscriber();
		$this->assertTrue( current_user_can( $capability ) );
	}
}

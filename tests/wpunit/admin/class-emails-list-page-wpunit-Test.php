<?php
/**
 * WPUnit tests for the emails list-table row actions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;
use WP_Post;
use ZBateson\MailMimeParser\IMessage;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Emails_List_Page
 */
class Emails_List_Page_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * The emails CPT name used across these tests.
	 *
	 * @var string
	 */
	private string $post_type = 'test_list_email';

	/**
	 * Build the SUT with a mocked API + repository.
	 *
	 * @param bool $can_delete        Whether the resolved connection supports delete-on-server.
	 * @param bool $is_remote_deleted Whether the email is already deleted on the server.
	 * @param bool $has_account       Whether an account resolves for the email.
	 */
	private function make_sut(
		bool $can_delete = true,
		bool $is_remote_deleted = false,
		bool $has_account = true
	): Emails_List_Page {

		$email = new BH_Email(
			post_id: 4242,
			post_type: $this->post_type,
			email_account_local_id: 9876,
			imessage: Mockery::mock( IMessage::class ),
			message_id: 'row-action@example.org',
			subject: 'Row action test',
			from_email: 'sender@example.org',
			is_remote_deleted: $is_remote_deleted,
		);

		$repository = Mockery::mock( Email_WP_Post_Repository::class );
		$repository->allows( 'find_by_post_id' )->andReturn( $email );

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $this->post_type );

		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();

		if ( $has_account ) {
			$account    = BH_Email_Account_Fixture::make( post_id: 7 );
			$connection = Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
			$connection->allows( 'can_delete_on_server' )->andReturn( $can_delete );
			$api->allows( 'get_email_account_for_email' )->andReturn( $account );
			$api->allows( 'get_connection_for_email_account' )->andReturn( $connection );
		} else {
			$api->allows( 'get_email_account_for_email' )->andReturnNull();
		}

		return new Emails_List_Page( $repository, $api, $settings, $this->logger );
	}

	private function make_post(): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'        => 4242,
				'post_type' => $this->post_type,
			)
		);
	}

	/**
	 * Reset the request superglobal so status/account query params don't leak between tests.
	 */
	public function tearDown(): void {
		unset( $_GET['post_status'], $_GET['bh_email_account'] );
		parent::tearDown();
	}

	/**
	 * Build a main-query WP_Query for our emails CPT in the admin context, as `pre_get_posts` would see it.
	 */
	private function make_admin_main_query(): \WP_Query {
		set_current_screen( 'edit.php' ); // Makes is_admin() true.

		$query = new \WP_Query();
		$query->set( 'post_type', $this->post_type );

		// is_main_query() compares against the global "main" query.
		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}

	/**
	 * "Trash" is relabelled "Trash locally" for email rows.
	 *
	 * @covers ::row_actions
	 */
	public function test_trash_is_relabelled_trash_locally(): void {
		$actions = $this->make_sut()->row_actions(
			array( 'trash' => '<a href="#" class="submitdelete" aria-label="Move to the Trash">Trash</a>' ),
			$this->make_post()
		);

		$this->assertStringContainsString( '>Trash locally<', $actions['trash'] );
		$this->assertStringNotContainsString( '>Trash<', $actions['trash'] );
	}

	/**
	 * "Delete on server" is added when the connection supports it and the email is not already deleted.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_added_when_supported(): void {
		$actions = $this->make_sut( can_delete: true, is_remote_deleted: false )->row_actions( array(), $this->make_post() );

		$this->assertArrayHasKey( 'bh_delete_on_server', $actions );
		$this->assertStringContainsString( 'Delete on server', $actions['bh_delete_on_server'] );
		$this->assertStringContainsString( 'data-post-id="4242"', $actions['bh_delete_on_server'] );
		$this->assertStringContainsString( 'bh-email-delete-on-server', $actions['bh_delete_on_server'] );
	}

	/**
	 * "Delete on server" is absent when the connection cannot delete on the server.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_absent_when_connection_cannot_delete(): void {
		$actions = $this->make_sut( can_delete: false )->row_actions( array(), $this->make_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * "Delete on server" is absent when the email is already deleted on the server.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_absent_when_already_deleted(): void {
		$actions = $this->make_sut( can_delete: true, is_remote_deleted: true )->row_actions( array(), $this->make_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * "Delete on server" is absent when no account resolves for the email.
	 *
	 * @covers ::row_actions
	 */
	public function test_delete_on_server_absent_when_no_account(): void {
		$actions = $this->make_sut( has_account: false )->row_actions( array(), $this->make_post() );

		$this->assertArrayNotHasKey( 'bh_delete_on_server', $actions );
	}

	/**
	 * "Quick Edit" is removed — emails are immutable, read-only records.
	 *
	 * @covers ::row_actions
	 */
	public function test_quick_edit_is_removed(): void {
		$actions = $this->make_sut()->row_actions(
			array( 'inline hide-if-no-js' => '<button type="button" class="button-link editinline">Quick&nbsp;Edit</button>' ),
			$this->make_post()
		);

		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $actions );
	}

	/**
	 * The default "All" view forces `post_status => any` so the custom email statuses are shown.
	 *
	 * @covers ::show_all_post_statuses
	 */
	public function test_show_all_post_statuses_defaults_to_any(): void {
		$query = $this->make_admin_main_query();

		$this->make_sut()->show_all_post_statuses( $query );

		$this->assertSame( 'any', $query->get( 'post_status' ) );
	}

	/**
	 * A specific status selected from the status-list links is respected (the filter-by-status bug regression).
	 *
	 * @covers ::show_all_post_statuses
	 */
	public function test_show_all_post_statuses_respects_explicit_status(): void {
		$_GET['post_status'] = 'bh_email_new';

		$query = $this->make_admin_main_query();
		$query->set( 'post_status', 'bh_email_new' );

		$this->make_sut()->show_all_post_statuses( $query );

		// Not clobbered to "any".
		$this->assertSame( 'bh_email_new', $query->get( 'post_status' ) );
	}

	/**
	 * Selecting an account in the filter dropdown constrains the query to that account's emails.
	 *
	 * @covers ::filter_by_account
	 */
	public function test_filter_by_account_sets_post_parent(): void {
		$_GET['bh_email_account'] = '7';

		$query = $this->make_admin_main_query();

		$this->make_sut()->filter_by_account( $query );

		$this->assertSame( 7, $query->get( 'post_parent' ) );
	}

	/**
	 * With no account selected, the query is left unconstrained (all accounts).
	 *
	 * @covers ::filter_by_account
	 */
	public function test_filter_by_account_ignored_without_selection(): void {
		$query = $this->make_admin_main_query();

		$this->make_sut()->filter_by_account( $query );

		$this->assertEmpty( $query->get( 'post_parent' ) );
	}

	/**
	 * Rows of other post types are left untouched.
	 *
	 * @covers ::row_actions
	 */
	public function test_other_post_types_are_unchanged(): void {
		$original = array( 'trash' => '<a href="#" class="submitdelete">Trash</a>' );
		$post     = new WP_Post(
			(object) array(
				'ID'        => 99,
				'post_type' => 'post',
			)
		);

		$actions = $this->make_sut()->row_actions( $original, $post );

		$this->assertSame( $original, $actions );
	}
}

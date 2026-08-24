<?php
/**
 * WPUnit tests for API.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_WPUnit_Test extends WPUnit_Testcase {

	// -------------------------------------------------------------------------
	// Order notes / log comments
	// -------------------------------------------------------------------------

	/**
	 * Returns an API instance with all it dependencies mocked unless they are specified.
	 *
	 * @param ?BH_WP_Mailboxes_Settings_Interface $settings Plugin slug, cpt name, cron schedules.
	 * @param ?Email_WP_Post_Repository           $email_repository Respository to save emails.
	 * @param ?Email_Account_WP_Post_Repository   $email_account_repository Repository to save email accounts.
	 * @param ?Private_Uploads                    $private_uploads Library to save attachments.
	 */
	protected function get_api(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?Email_WP_Post_Repository $email_repository = null,
		?Email_Account_WP_Post_Repository $email_account_repository = null,
		?Private_Uploads $private_uploads = null,
	): API {
		return new API(
			$settings ?? \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
			$email_repository ?? \Mockery::mock( Email_WP_Post_Repository::class ),
			$email_account_repository ?? \Mockery::mock( Email_Account_WP_Post_Repository::class ),
			new New_Email_Factory(),
			$private_uploads ?? \Mockery::mock( Private_Uploads::class ),
			$this->logger,
		);
	}

	/**
	 * Requirement 7: insert_email_log_note creates a WP comment with comment_type 'bh_email_log'.
	 *
	 * This confirms that status-change logs are stored in a way that lets them be
	 * rendered like WooCommerce order notes (same pattern: custom comment_type on the post).
	 *
	 * @covers ::insert_email_log_note
	 */
	public function test_insert_email_log_note_creates_comment_with_bh_email_log_type(): void {

		$post_type = 'test_api_email';
		if ( ! post_type_exists( $post_type ) ) {
			register_post_type( $post_type, array( 'public' => false ) );
		}

		$repository = new Email_WP_Post_Repository(
			$post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger,
		);

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$api = $this->get_api( email_repository: $repository );

		$api->insert_email_log_note( $post_id, 'Status changed from "bh_email_new" to "bh_email_processed".' );

		/**
		 * Without `count => true`, `get_comments()` returns an array of comments.
		 *
		 * @var \WP_Comment[] $comments
		 */
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'bh_email_log',
			)
		);

		// The email also carries an automatic "downloaded" log entry, so locate the status-change note.
		$status_notes = array_values(
			array_filter( $comments, fn( $comment ) => str_contains( $comment->comment_content, 'bh_email_processed' ) )
		);

		$this->assertCount( 1, $status_notes, 'Exactly one status-change bh_email_log comment should exist' );
		$this->assertSame( 'bh_email_log', $status_notes[0]->comment_type );
		$this->assertStringContainsString( 'bh_email_processed', $status_notes[0]->comment_content );
	}

	/**
	 * Register the accounts CPT and build an API instance backed by a real account repository.
	 *
	 * @param string $post_type The accounts CPT key to register.
	 *
	 * @return array{0:\BrianHenryIE\WP_Mailboxes\API\API, 1:Email_Account_WP_Post_Repository}
	 */
	protected function get_api_with_account_repository( string $post_type ): array {

		$settings = \Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $post_type );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test API Accounts' );
		$settings->allows( 'get_rest_namespace' )->andReturn( null );

		$cpt = new BH_Email_Account_CPT( $settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();

		$account_repository = new Email_Account_WP_Post_Repository(
			$post_type,
			new BH_Email_Account_Factory( $this->logger ),
			$this->logger,
		);

		return array( $this->get_api( settings: $settings, email_account_repository: $account_repository ), $account_repository );
	}

	/**
	 * The address is the unique id: configure_email_account() creates distinct accounts per address.
	 *
	 * Regression (from the former add_email_account()): the dedup query filtered by
	 * post_name/meta_input (both ignored by WP_Query), so it matched every existing account and
	 * false-positived "already exists" once any account existed.
	 *
	 * @covers ::configure_email_account
	 */
	public function test_configure_email_account_creates_distinct_accounts_per_address(): void {

		[ $api ] = $this->get_api_with_account_repository( 'test_api_account' );

		$first = $api->configure_email_account( 'first@example.com', 'First', 'SomeConnection', null, null, null, null );
		// A second, distinct account must be allowed even though one already exists.
		$second = $api->configure_email_account( 'second@example.com', 'Second', 'SomeConnection', null, null, null, null );

		$this->assertSame( 'first@example.com', $first->email_address );
		$this->assertSame( 'second@example.com', $second->email_address );
	}

	/**
	 * Configuring an address that already has an account updates it in place rather than throwing;
	 * null values leave the existing configuration unchanged.
	 *
	 * @covers ::configure_email_account
	 */
	public function test_configure_email_account_updates_existing_account(): void {

		[ $api, $account_repository ] = $this->get_api_with_account_repository( 'test_api_acc_upd' );

		$created = $api->configure_email_account( 'inbox@example.com', 'Original Name', 'SomeConnection', null, null, 'mark_read', 30 );

		$updated = $api->configure_email_account( 'inbox@example.com', 'New Name', 'AnotherConnection', null, null, null, null );

		$this->assertSame( $created->get_post_id(), $updated->get_post_id(), 'The existing account post should be updated, not a new one created.' );
		$this->assertSame( 'New Name', $updated->display_name );
		$this->assertSame( 'AnotherConnection', $updated->connection_type_class );
		// Null leaves the existing values unchanged.
		$this->assertSame( 'mark_read', $updated->after_download_remote_email_action() );

		$this->assertCount( 1, $account_repository->get_all() );
	}

	/**
	 * Only the email address is required once an account exists, but creating an account needs the
	 * display name and connection class; a create attempt without them throws and creates nothing.
	 *
	 * @covers ::configure_email_account
	 */
	public function test_configure_email_account_throws_when_creating_without_required_configuration(): void {

		[ $api, $account_repository ] = $this->get_api_with_account_repository( 'test_api_acc_req' );

		$exception = null;
		try {
			$api->configure_email_account( 'new@example.com' );
		} catch ( \InvalidArgumentException $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Verified below.
		}

		$this->assertInstanceOf( \InvalidArgumentException::class, $exception );
		$this->assertStringContainsString( 'display_name and connection_type_class are required', $exception->getMessage() );
		$this->assertNull( $account_repository->find_by_email_address( 'new@example.com' ), 'No account should be created.' );

		// One-missing case names just the missing parameter.
		try {
			$api->configure_email_account( 'new@example.com', display_name: 'New Account' );
			$this->fail( 'An InvalidArgumentException should have been thrown.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'connection_type_class is required', $exception->getMessage() );
		}
	}

	/**
	 * Once the account exists, a later call needs only the email address plus whatever is changing.
	 *
	 * @covers ::configure_email_account
	 */
	public function test_configure_email_account_updates_with_only_email_address_and_changed_value(): void {

		[ $api ] = $this->get_api_with_account_repository( 'test_api_acc_min' );

		$created = $api->configure_email_account( 'min@example.com', 'Original Name', 'SomeConnection' );

		$updated = $api->configure_email_account( 'min@example.com', display_name: 'Renamed' );

		$this->assertSame( $created->get_post_id(), $updated->get_post_id() );
		$this->assertSame( 'Renamed', $updated->display_name );
		$this->assertSame( 'SomeConnection', $updated->connection_type_class );
	}

	/**
	 * A call with only the email address for an existing account is a no-op returning the account.
	 *
	 * @covers ::configure_email_account
	 */
	public function test_configure_email_account_with_only_email_address_returns_existing_account(): void {

		[ $api ] = $this->get_api_with_account_repository( 'test_api_acc_noop' );

		$created = $api->configure_email_account( 'noop@example.com', 'Noop Name', 'SomeConnection' );

		$result = $api->configure_email_account( 'noop@example.com' );

		$this->assertSame( $created->get_post_id(), $result->get_post_id() );
		$this->assertSame( 'Noop Name', $result->display_name );
	}

	/**
	 * Deleting permanently deletes the account's post; returns false when no account exists.
	 *
	 * @covers ::delete_email_account
	 */
	public function test_delete_email_account(): void {

		[ $api, $account_repository ] = $this->get_api_with_account_repository( 'test_api_acc_del' );

		$account = $api->configure_email_account( 'delete-me@example.com', 'Delete Me', 'SomeConnection', null, null, null, null );

		$this->assertTrue( $api->delete_email_account( 'delete-me@example.com' ) );

		$this->assertNull( $account_repository->find_by_email_address( 'delete-me@example.com' ) );
		$this->assertNull( get_post( $account->get_post_id() ), 'The post should be deleted, not trashed.' );

		$this->assertFalse( $api->delete_email_account( 'delete-me@example.com' ), 'Deleting a non-existent account should return false.' );
	}
}

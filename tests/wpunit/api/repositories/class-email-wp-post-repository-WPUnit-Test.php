<?php
/**
 * TODO: move to integration test?
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Queries\WP_Post_Query_Abstract;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Email_Status_Counts;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\Private_Uploads_Fixture;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads_API;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Trait;
use Codeception\Stub\Expected;
use Mockery;
use WP_Post;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository
 */
class Email_WP_Post_Repository_WPUnit_Test extends \BrianHenryIE\WP_Mailboxes\WPUnit_Testcase {

	/** @var BH_WP_Mailboxes_Settings_Interface Mocked settings used across the suite. */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Mockery::mock( \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->expects( 'get_emails_cpt_underscored_20' )->andReturn( 'test_post_type' );
		$this->settings->expects( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Post Type' );
		$this->settings->allows( 'get_rest_namespace' )->andReturn( null );

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();

		$cpt->register_post_statuses();
	}

	/**
	 * @covers ::save_new
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\WP_Post_Repository_Abstract::insert
	 */
	public function test_save_new(): void {

		$post_type        = 'test_post_type';
		$bh_email_factory = Mockery::mock( BH_Email_Factory::class );
		$bh_email_factory = new BH_Email_Factory( $this->logger );

		$sut = new Email_WP_Post_Repository( $post_type, $bh_email_factory );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' );
		$email_contents = file_get_contents( $email_filepath );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( $email_contents, true );

		$email_account = BH_Email_Account_Fixture::make(
			post_id: 456,
			post_type: $post_type,
			connection_type_class: 'SomeConnection',
			email_address: 'test@example.com',
			display_name: 'Test Account',
			delete_local_emails_after_n_days: null,
		);

		$result = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			$email_account
		);

		$this->assertEquals( '[Wordfence Alert] Problems found on bhwp.ie', $result->get_subject() );

		// "Date: Wed, 30 Jul 2025 03:38:07 +0000".
		$this->assertEquals( '2025-07-30 03:38:07', $result->get_sent_at()->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Saving a new email records an info-level "downloaded" entry in its log.
	 *
	 * @covers ::save_new
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\WP_Post_Repository_Abstract::log
	 */
	public function test_save_new_logs_downloaded(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		$parser = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( (string) codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' ) ), true );

		$result = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: $post_type ),
		);

		$log_notes = get_comments(
			array(
				'post_id' => $result->get_post_id(),
				'type'    => 'bh_email_log',
			)
		);

		$messages = array_map( fn( $comment ) => strtolower( (string) $comment->comment_content ), $log_notes );
		$this->assertNotEmpty(
			array_filter( $messages, fn( $message ) => str_contains( $message, 'downloaded' ) ),
			'A "downloaded" log note should be recorded on save.'
		);
	}

	/**
	 * Updating an email's local status records a "status changed" log entry (no WordPress hook involved).
	 *
	 * @covers ::update
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\WP_Post_Repository_Abstract::log
	 */
	public function test_update_logs_status_change(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		$parser = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( (string) codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' ) ), true );

		$saved = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: $post_type ),
		);

		$sut->update( $saved, local_status: 'bh_email_processed' );

		$log_notes = get_comments(
			array(
				'post_id' => $saved->get_post_id(),
				'type'    => 'bh_email_log',
			)
		);

		$messages = array_map( fn( $comment ) => (string) $comment->comment_content, $log_notes );
		$this->assertNotEmpty(
			array_filter( $messages, fn( $message ) => str_contains( $message, 'Status changed' ) ),
			'A "status changed" log note should be recorded on update.'
		);
	}

	/**
	 * Fetching the same email twice (same account + Message-ID) must not create two posts. The second
	 * save_new should return the already-saved post, matched on the indexed message-id slug.
	 *
	 * @covers ::save_new
	 * @covers ::message_id_slug
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\WP_Post_Repository_Abstract::insert
	 */
	public function test_save_new_dedups_by_message_id(): void {

		$post_type        = 'test_post_type';
		$bh_email_factory = new BH_Email_Factory( $this->logger );

		$sut = new Email_WP_Post_Repository( $post_type, $bh_email_factory, $this->logger );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' );
		$email_contents = file_get_contents( $email_filepath );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( $email_contents, true );

		$email_account = BH_Email_Account_Fixture::make(
			post_id: 456,
			post_type: $post_type,
			connection_type_class: 'SomeConnection',
			email_address: 'test@example.com',
			display_name: 'Test Account',
			delete_local_emails_after_n_days: null,
		);

		$first = $sut->save_new( $this->make_fetched_email( $email ), $this->settings, $email_account );

		// The dedup key is stored in the indexed post_name (slug).
		$expected_slug = Email_WP_Post_Repository::message_id_slug( 'test@example.com', $email->getMessageId() ?? '' );
		$this->assertSame( $expected_slug, get_post( $first->get_post_id() )->post_name );

		$second = $sut->save_new( $this->make_fetched_email( $email ), $this->settings, $email_account );

		$this->assertSame(
			$first->get_post_id(),
			$second->get_post_id(),
			'Saving the same email twice should return the same post.'
		);

		$this->assertSame(
			1,
			$sut->count_for_account_email( $email_account ),
			'Only one post should exist for the deduplicated email.'
		);
	}

	/**
	 * The remote coordinates and read state captured at fetch time must persist to post meta and
	 * rehydrate via from_wp_post().
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_persists_remote_coordinates(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( $email_filepath ), true );

		$email_account = BH_Email_Account_Fixture::make(
			post_id: 456,
			post_type: $post_type,
			connection_type_class: 'SomeConnection',
			email_address: 'test@example.com',
			display_name: 'Test Account',
			delete_local_emails_after_n_days: null,
		);

		$coordinates = new Remote_Email_Coordinates(
			message_id: $email->getMessageId() ?? '',
			remote_uid: '4242',
			folder: 'INBOX',
			uid_validity: 99,
		);

		$result = $sut->save_new(
			new Fetched_Email( $email, $coordinates, true ),
			$this->settings,
			$email_account,
		);

		$post_id = $result->get_post_id();
		$this->assertSame( '4242', get_post_meta( $post_id, 'remote_uid', true ) );
		$this->assertSame( 'INBOX', get_post_meta( $post_id, 'remote_folder', true ) );
		$this->assertSame( '99', get_post_meta( $post_id, 'remote_uid_validity', true ) );

		// Rehydrate through the factory.
		$rehydrated = $sut->find_by_post_id( $post_id );
		$this->assertTrue( $rehydrated->is_remote_read );

		$rehydrated_coordinates = $rehydrated->get_remote_coordinates();
		$this->assertNotNull( $rehydrated_coordinates );
		$this->assertSame( '4242', $rehydrated_coordinates->remote_uid );
		$this->assertSame( 'INBOX', $rehydrated_coordinates->folder );
		$this->assertSame( 99, $rehydrated_coordinates->uid_validity );
	}

	/**
	 * With a real private-uploads API, an email's attachment is written to disk, a post recording it
	 * is created (parented to the email), and that post id is stored on the email and rehydrated.
	 *
	 * Drives the actual `save_attachments()` path end-to-end: MIME part → temp file →
	 * `move_file_to_private_uploads_and_create_post()` → post id.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_saves_attachments(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( $email_filepath ), true );

		$result = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: $post_type ),
			$this->make_private_uploads(),
		);

		// One attachment → one real post id recorded on the email and rehydrated from meta.
		$ids = $result->attachment_ids;
		$this->assertIsArray( $ids );
		$this->assertCount( 1, $ids );
		$attachment_id = $ids[0];
		$this->assertSame( $ids, $sut->find_by_post_id( $result->get_post_id() )->attachment_ids );

		// The recording post exists and is parented to the email.
		$attachment_post = get_post( $attachment_id );
		$this->assertInstanceOf( WP_Post::class, $attachment_post );
		$this->assertSame( $result->get_post_id(), $attachment_post->post_parent );

		// The file is really on disk with the attachment's decoded content.
		$file = get_attached_file( $attachment_id );
		$this->assertIsString( $file );
		$this->assertFileExists( $file );
		$this->assertStringEndsWith( '.txt', $file );
		$this->assertSame( "hello world\n", file_get_contents( $file ) );

		// The moved file lives outside the test DB transaction; remove it.
		wp_delete_file( $file );
	}

	/**
	 * Cron and WP-CLI run with no logged-in user. `bh-wp-private-uploads` 0.3.0 rejected uploads when
	 * `current_user_can( 'upload_files' )` was false, and `save_attachments()` catches and logs that
	 * failure rather than rethrowing, so every scheduled fetch silently discarded its attachments.
	 * 0.4.0 removed the capability check.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_saves_attachments_with_no_current_user(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		wp_set_current_user( 0 );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( $email_filepath ), true );

		$result = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: $post_type ),
			$this->make_private_uploads(),
		);

		$ids = $result->attachment_ids;
		$this->assertIsArray( $ids );
		$this->assertCount( 1, $ids );

		$file = get_attached_file( $ids[0] );
		$this->assertIsString( $file );
		$this->assertFileExists( $file );
		$this->assertSame( "hello world\n", file_get_contents( $file ) );

		// The moved file lives outside the test DB transaction; remove it.
		wp_delete_file( $file );
	}

	/**
	 * Without a private-uploads API, attachment-saving is disabled: attachment_ids is null
	 * ("Attachments disabled"), not an empty array.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_attachments_disabled_when_no_private_uploads(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( $email_filepath ), true );

		$result = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: $post_type ),
		);

		$this->assertNull( $result->attachment_ids );
		$this->assertSame( '', get_post_meta( $result->get_post_id(), 'attachment_ids', true ) );
	}

	/**
	 * Wrap a parsed message in a Fetched_Email with minimal coordinates for save_new().
	 *
	 * @param IMessage $email The parsed email.
	 */
	private function make_fetched_email( IMessage $email ): Fetched_Email {
		return new Fetched_Email(
			$email,
			new Remote_Email_Coordinates( message_id: $email->getMessageId() ?? '' ),
			false,
		);
	}

	/**
	 * A real private-uploads API writing to its own test subdirectory.
	 */
	private function make_private_uploads(): Private_Uploads_API {

		/** @var Private_Uploads_Settings_Interface $settings */
		$settings = new class() implements Private_Uploads_Settings_Interface {
			use Private_Uploads_Settings_Trait;

			public function get_plugin_slug(): string {
				return 'bh-wp-mailboxes-test';
			}

			public function get_uploads_subdirectory_name(): string {
				return 'bh-wp-mailboxes-test-attachments';
			}
		};

		return new Private_Uploads_API( $settings, $this->logger );
	}

	/**
	 * Log_status_change does nothing when the status has not changed.
	 *
	 * @covers ::log
	 */
	public function test_log_status_change_skips_when_status_unchanged(): void {

		$this->markTestSkipped( 'Will be reimplementing in the repository.' );

		register_post_type(
			$this->post_type,
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => $this->post_type,
				'post_status' => 'bh_email_new',
			)
		);

		$post_before = get_post( $post_id );
		$post_after  = clone $post_before;

		$api = $this->makeEmpty(
			API_Interface::class,
			array(
				'insert_email_log_note' => Expected::never(),
			)
		);

		$sut = new Single_Email_View( $this->make_settings(), $api, $this->make_repository(), $this->logger );
		$sut->log_status_change( $post_id, $post_after, $post_before );
	}

	public function test_invalid_characters(): void {

		$sut = new class() extends WP_Post_Repository_Abstract {
			public function test_insert( WP_Post_Query_Abstract $query ): int {
				return $this->insert( $query );
			}
		};
		$sut->setLogger( $this->logger );

		$result = $sut->test_insert(
			new readonly class() extends WP_Post_Query_Abstract {
				public function __construct() {
					parent::__construct( 'post' );
				}

				public function to_wp_post_array(): array {
					return array(
						// "\xF0" is a four-byte UTF-8 lead byte with no continuation bytes, i.e. invalid UTF-8,
						// as seen in a real email whose body contained directory traversal probe strings.
						'post_content' => "Blocked for Directory Traversal in query string: src = ../../../../../../../.env\xF0.php",
					);
				}
				protected function get_meta_input(): array {
					return array();
				}
			}
		);

		$this->assertIsInt( $result );
	}

	/**
	 * Non-trashed emails are counted per local status; unknown statuses land in `other_count`, trash and
	 * other accounts' emails are excluded.
	 *
	 * @covers ::count_by_status_for_account_email
	 */
	public function test_count_by_status_for_account_email(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );

		$account       = BH_Email_Account_Fixture::make( post_id: 500, post_type: 'test_accounts' );
		$other_account = BH_Email_Account_Fixture::make( post_id: 501, post_type: 'test_accounts' );

		$make = function ( int $account_post_id, string $status ) use ( $post_type ): void {
			$this->factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_status' => $status,
					'post_parent' => $account_post_id,
				)
			);
		};

		$make( 500, 'bh_email_new' );
		$make( 500, 'bh_email_new' );
		$make( 500, 'bh_email_processed' );
		$make( 500, 'bh_email_saved' );
		$make( 500, 'publish' );
		$make( 500, 'trash' );
		$make( 501, 'bh_email_new' );

		$counts = $sut->count_by_status_for_account_email( $account );

		$this->assertSame( 2, $counts->new_count );
		$this->assertSame( 1, $counts->processed_count );
		$this->assertSame( 1, $counts->saved_count );
		$this->assertSame( 1, $counts->other_count );
		$this->assertSame( 5, $counts->total() );
		$this->assertSame( $sut->count_for_account_email( $account ), $counts->total(), 'Matches the plain count.' );

		$empty = $sut->count_by_status_for_account_email( $other_account );
		$this->assertSame( 1, $empty->total() );
		$this->assertSame( 1, $empty->new_count );

		$none = $sut->count_by_status_for_account_email( BH_Email_Account_Fixture::make( post_id: 502, post_type: 'test_accounts' ) );
		$this->assertSame( 0, $none->total() );
	}

	/**
	 * The per-status counts are cached in the `counts` group, keyed on the posts "last changed" token
	 * as WP_Query's cache is, so without registering any hooks every way an email can change (save,
	 * status update, trash, untrash, permanent delete) is reflected in the next count, and a repeat
	 * call with nothing changed is served from the cache. The plain count is derived from the same
	 * cached counts.
	 *
	 * @covers ::count_by_status_for_account_email
	 * @covers ::count_for_account_email
	 */
	public function test_count_by_status_is_cached_on_posts_last_changed(): void {

		$post_type = 'test_post_type';
		$sut       = new Email_WP_Post_Repository( $post_type, new BH_Email_Factory( $this->logger ), $this->logger );
		$account   = BH_Email_Account_Fixture::make( post_id: 600, post_type: 'test_accounts' );

		// Register the custom statuses so wp_update_post()/untrash keep them; restore-on-untrash as in production.
		$settings = Mockery::mock( \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $post_type );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Emails' );
		$cpt = new \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT( $settings, $this->logger );
		$cpt->register_post_statuses();
			add_filter( 'wp_untrash_post_status', $cpt->restore_status_on_untrash( ... ), 10, 3 );

			$this->assertSame( 0, $sut->count_by_status_for_account_email( $account )->total(), 'Primes the cache.' );

			$key_for = fn(): string => 'bh_email_status_counts:test_post_type:600:' . wp_cache_get_last_changed( 'posts' );
			$this->assertInstanceOf( Email_Status_Counts::class, wp_cache_get( $key_for(), 'counts' ), 'The result is stored in the counts group under the last-changed key.' );

			$email_id = $this->factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_status' => 'bh_email_new',
					'post_parent' => 600,
				)
			);
			$this->assertSame( 1, $sut->count_by_status_for_account_email( $account )->new_count, 'A saved email is counted.' );

			// A repeat call with nothing changed is a cache hit: the SQL is not re-run.
			global $wpdb;
			$queries_before = $wpdb->num_queries;
			$this->assertSame( 1, $sut->count_for_account_email( $account ), 'The plain count is the cached total.' );
			$this->assertSame( $queries_before, $wpdb->num_queries, 'Served from the cache.' );

			wp_update_post(
				array(
					'ID'          => $email_id,
					'post_status' => 'bh_email_processed',
				)
			);
			$counts = $sut->count_by_status_for_account_email( $account );
			$this->assertSame( 0, $counts->new_count );
			$this->assertSame( 1, $counts->processed_count, 'A status change is counted.' );

			wp_trash_post( $email_id );
			$this->assertSame( 0, $sut->count_by_status_for_account_email( $account )->total(), 'A trashed email is not counted.' );

			wp_untrash_post( $email_id );
			$this->assertSame( 1, $sut->count_by_status_for_account_email( $account )->processed_count, 'An untrashed email is counted again, in its restored status.' );

			wp_delete_post( $email_id, true );
			$this->assertSame( 0, $sut->count_by_status_for_account_email( $account )->total(), 'A deleted email is not counted.' );
	}
	/**
	 * Attachment post ids whose files a test created; deleted in tearDown (files outlive the DB rollback).
	 *
	 * @var int[]
	 */
	private array $attachment_ids_to_clean_up = array();

	protected function tearDown(): void {
		Private_Uploads_Fixture::delete_files( $this->attachment_ids_to_clean_up );
		$this->attachment_ids_to_clean_up = array();
		parent::tearDown();
	}

	/**
	 * Save a tests/_data/wpunit fixture through the repository.
	 *
	 * @param string                         $eml_file        The fixture filename.
	 * @param ?Private_Uploads_API_Interface $private_uploads Where attachments are saved; null disables them.
	 */
	private function save_fixture( string $eml_file, ?Private_Uploads_API_Interface $private_uploads ): BH_Email {
		$sut = new Email_WP_Post_Repository( 'test_post_type', new BH_Email_Factory( $this->logger ), $this->logger );

		/** @var IMessage $message */
		$message = ( new MailMimeParser() )->parse( (string) file_get_contents( (string) codecept_root_dir( "tests/_data/wpunit/{$eml_file}" ) ), true );

		$email = $sut->save_new(
			$this->make_fetched_email( $message ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: 'test_post_type' ),
			$private_uploads,
		);

		$this->attachment_ids_to_clean_up = array_merge( $this->attachment_ids_to_clean_up, $email->attachment_ids ?? array() );

		return $email;
	}

	/**
	 * Every attachment of an email is saved, in MIME order, each file byte for byte (text and binary) under its
	 * own filename and mime type.
	 *
	 * @covers ::save_new
	 * @covers ::save_attachments
	 */
	public function test_save_new_saves_every_attachment_byte_for_byte(): void {
		$email = $this->save_fixture( 'with-two-attachments.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$ids = $email->attachment_ids;
		$this->assertIsArray( $ids );
		$this->assertCount( 2, $ids );

		$expected = array(
			array( 'notes.txt', 'text/plain', base64_decode( 'Rmlyc3QgbGluZQpTZWNvbmQgbGluZQo=' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The fixture's attachment bytes.
			array( 'pixel.png', 'image/png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The fixture's attachment bytes.
		);

		foreach ( $expected as $index => list( $filename, $mime_type, $bytes ) ) {
			$file = get_attached_file( $ids[ $index ] );
			$this->assertIsString( $file );
			$this->assertSame( $filename, basename( $file ), "Attachment {$index} keeps its filename, in MIME order." );
			$this->assertSame( $bytes, file_get_contents( $file ), "Attachment {$index} is saved byte for byte." );
			$this->assertSame( $mime_type, get_post_mime_type( $ids[ $index ] ) );
			$this->assertSame( $email->post_id, get_post( $ids[ $index ] )?->post_parent );
		}
	}

	/**
	 * The stored original message excludes the attachments, which are saved separately.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_stores_the_message_without_its_attachments(): void {
		$this->markTestSkipped( 'The stored message still contains every attachment: https://github.com/BrianHenryIE/bh-wp-mailboxes/issues/152' );

		$email = $this->save_fixture( 'with-attachment.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$stored = (string) get_post_field( 'post_content', $email->post_id );

		$this->assertStringContainsString( 'This email has an attachment.', $stored );
		$this->assertStringNotContainsString( 'aGVsbG8gd29ybGQK', $stored, 'The attachment body is not stored in the email post.' );
		$this->assertStringNotContainsString( 'aGVsbG8gd29ybGQK', $email->original_mime_message );
	}

	/**
	 * With attachments enabled, an email without attachments records an empty list, distinct from "disabled".
	 *
	 * @covers ::save_new
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory::from_wp_post
	 */
	public function test_save_new_records_no_attachments_as_an_empty_list(): void {
		$email = $this->save_fixture( 'html-and-plaintext.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$this->assertSame( array(), $email->attachment_ids );
		$this->assertSame( '[]', get_post_meta( $email->post_id, 'attachment_ids', true ) );
	}

	/**
	 * One attachment failing to save is logged; the email and its other attachments are still saved, and the
	 * temporary file is removed.
	 *
	 * @covers ::save_attachments
	 */
	public function test_save_new_keeps_the_email_when_an_attachment_fails(): void {
		$real_private_uploads = Private_Uploads_Fixture::make( $this->logger );

		$temp_files      = array();
		$private_uploads = Mockery::mock( Private_Uploads_API_Interface::class );
		$private_uploads->allows( 'move_file_to_private_uploads_and_create_post' )->andReturnUsing(
			function ( string $tmp_file, string $filename, ?int $post_author_id = null, ?int $post_parent_id = null ) use ( &$temp_files, $real_private_uploads ) {
				$temp_files[] = $tmp_file;
				if ( 'notes.txt' === $filename ) {
					throw new \RuntimeException( 'Disk full.' );
				}
				return $real_private_uploads->move_file_to_private_uploads_and_create_post( tmp_file: $tmp_file, filename: $filename, post_parent_id: $post_parent_id );
			}
		);

		$email = $this->save_fixture( 'with-two-attachments.eml', $private_uploads );

		$this->assertGreaterThan( 0, $email->post_id, 'The email is saved.' );
		$this->assertCount( 1, $email->attachment_ids ?? array(), 'The other attachment is saved.' );
		$this->assertSame( 'pixel.png', basename( (string) get_attached_file( ( $email->attachment_ids ?? array() )[0] ) ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Failed to save email attachment.' ) );

		$this->assertCount( 2, $temp_files );
		foreach ( $temp_files as $temp_file ) {
			$this->assertFileDoesNotExist( $temp_file, 'No temporary file is left behind.' );
		}
	}

	/**
	 * An attachment part with no filename is saved as "attachment".
	 *
	 * @covers ::save_attachments
	 */
	public function test_save_new_names_an_unnamed_attachment(): void {
		$email = $this->save_fixture( 'attachment-without-filename.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$this->assertCount( 1, $email->attachment_ids ?? array() );
		$file = get_attached_file( ( $email->attachment_ids ?? array() )[0] );
		$this->assertIsString( $file );
		$this->assertStringStartsWith( 'attachment', basename( $file ) );
		$this->assertSame( "hello world\n", file_get_contents( $file ) );
	}

	/**
	 * A filename that tries to leave the directory is saved inside the private uploads directory.
	 *
	 * @covers ::save_attachments
	 */
	public function test_save_new_keeps_an_unsafe_filename_inside_the_private_uploads_directory(): void {
		$email = $this->save_fixture( 'attachment-unsafe-filename.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$this->assertCount( 1, $email->attachment_ids ?? array() );
		$file = get_attached_file( ( $email->attachment_ids ?? array() )[0] );
		$this->assertIsString( $file );

		$private_uploads_directory = wp_upload_dir( null, false )['basedir'] . '/bh-wp-mailboxes-test-attachments/';
		$this->assertStringStartsWith( $private_uploads_directory, $file );
		$this->assertStringNotContainsString( '..', $file );
		$this->assertStringEndsWith( 'evil.txt', $file );
	}

	/**
	 * Saving the same email again (a re-fetch) does not save its attachments again.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_does_not_duplicate_attachments_of_a_refetched_email(): void {
		$first  = $this->save_fixture( 'with-attachment.eml', Private_Uploads_Fixture::make( $this->logger ) );
		$second = $this->save_fixture( 'with-attachment.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$this->assertSame( $first->post_id, $second->post_id );
		$this->assertSame( $first->attachment_ids, $second->attachment_ids );

		$attachment_post_type = get_post_type( ( $first->attachment_ids ?? array() )[0] );
		$this->assertIsString( $attachment_post_type );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => $attachment_post_type,
					'post_parent' => $first->post_id,
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);
	}
	/**
	 * An unnamed attachment whose content type WordPress has no extension for is named "attachment"; the upload's
	 * file type check then rejects it. That is logged, and the email is still saved.
	 *
	 * @covers ::save_attachments
	 */
	public function test_save_new_logs_an_unnamed_attachment_of_unknown_type(): void {
		$email = $this->save_fixture( 'attachment-unknown-type.eml', Private_Uploads_Fixture::make( $this->logger ) );

		$this->assertGreaterThan( 0, $email->post_id, 'The email is saved.' );
		$this->assertSame( array(), $email->attachment_ids );
		$this->assertTrue(
			$this->logger->hasErrorThatPasses(
				fn( array $record ): bool => 'Failed to save email attachment.' === $record['message']
					&& 'attachment' === ( $record['context']['filename'] ?? null )
			),
			'The failure is logged with the fallback filename.'
		);
	}
}

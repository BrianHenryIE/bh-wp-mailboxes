<?php
/**
 * WPUnit tests for cascading an email's permanent deletion to its attachments.
 *
 * An email with a real attachment (file on disk, private-uploads post parented to the email) and a log
 * note is created through the repository, then deleted through the WordPress-native path and through the
 * library's own command; both must leave nothing behind. Trashing must leave everything in place.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\New_Email_Local;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads_API;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Trait;
use Mockery;
use WP_Post;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Email_Post_Deletion_Handler
 */
class Email_Post_Deletion_Handler_WPUnit_Test extends WPUnit_Testcase {

	const POST_TYPE = 'test_del_email';

	/**
	 * Mocked settings naming the test post type.
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * A real repository, so emails and attachments are created the way production creates them.
	 *
	 * @var Email_WP_Post_Repository
	 */
	protected Email_WP_Post_Repository $repository;

	/**
	 * The hooked callback, removed in tearDown.
	 *
	 * @var callable
	 */
	protected $callback;

	/**
	 * Files created outside the DB transaction, removed in tearDown if a test did not.
	 *
	 * @var string[]
	 */
	protected array $files = array();

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( self::POST_TYPE );
		$this->settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Deletion Test Emails' );
		$this->settings->allows( 'get_rest_namespace' )->andReturn( null );

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();
		$this->untrash_filter = $cpt->restore_status_on_untrash( ... );
		add_filter( 'wp_untrash_post_status', $this->untrash_filter, 10, 3 );

		$this->repository = new Email_WP_Post_Repository( self::POST_TYPE, new BH_Email_Factory( $this->logger ), $this->logger );

		$this->callback = ( new Email_Post_Deletion_Handler( $this->settings, $this->logger ) )->delete_attachments( ... );
		add_action( 'before_delete_post', $this->callback, 10, 2 );
	}

	/**
	 * The untrash-status filter, removed in tearDown.
	 *
	 * @var callable
	 */
	protected $untrash_filter;

	protected function tearDown(): void {
		remove_action( 'before_delete_post', $this->callback, 10 );
		remove_filter( 'wp_untrash_post_status', $this->untrash_filter, 10 );
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		unregister_post_type( self::POST_TYPE );
		parent::tearDown();
	}

	/**
	 * An email with one real attachment on disk and one log note.
	 *
	 * @return array{email: BH_Email, attachment_id: int, file: string}
	 */
	protected function make_email_with_attachment(): array {
		$parser = new MailMimeParser();
		/** @var IMessage $message */
		$message = $parser->parse( (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' ) ), true );

		$email = $this->repository->save_new(
			new Fetched_Email( $message, new Remote_Email_Coordinates( message_id: $message->getMessageId() ?? 'del-' . wp_rand() ), false ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: self::POST_TYPE ),
			$this->make_private_uploads(),
		);

		$this->assertIsArray( $email->attachment_ids );
		$this->assertCount( 1, $email->attachment_ids );
		$attachment_id = $email->attachment_ids[0];
		$file          = get_attached_file( $attachment_id );
		$this->assertIsString( $file );
		$this->assertFileExists( $file );
		$this->files[] = $file;

		wp_insert_comment(
			array(
				'comment_post_ID' => $email->get_post_id(),
				'comment_content' => 'A log note.',
				'comment_type'    => 'bh_email_log',
			)
		);
		// The repository logs the download as a note too, so there are at least two.
		$this->assertGreaterThanOrEqual( 2, count( get_comments( array( 'post_id' => $email->get_post_id() ) ) ) );

		return array(
			'email'         => $email,
			'attachment_id' => $attachment_id,
			'file'          => $file,
		);
	}

	/**
	 * A real private-uploads API writing to its own test subdirectory.
	 */
	protected function make_private_uploads(): Private_Uploads_API {
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
	 * The WordPress-native path: `wp_delete_post()` directly removes the attachment post, its file and
	 * the log notes.
	 *
	 * @covers ::delete_attachments
	 * @covers ::get_attachment_ids
	 * @covers ::__construct
	 */
	public function test_native_permanent_delete_removes_attachments_files_and_notes(): void {
		[ 'email' => $email, 'attachment_id' => $attachment_id, 'file' => $file ] = $this->make_email_with_attachment();

		$this->assertInstanceOf( WP_Post::class, wp_delete_post( $email->get_post_id(), true ) );

		$this->assertNull( get_post( $email->get_post_id() ) );
		$this->assertNull( get_post( $attachment_id ), 'The attachment post is deleted with the email.' );
		$this->assertFileDoesNotExist( $file, 'The attachment file is deleted with the email.' );
		$this->assertCount( 0, get_comments( array( 'post_id' => $email->get_post_id() ) ) );
	}

	/**
	 * The library's own command gives the identical result.
	 *
	 * @covers ::delete_attachments
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Model\New_Email_Local::delete_local_email_post
	 */
	public function test_delete_local_email_post_removes_attachments_files_and_notes(): void {
		[ 'email' => $email, 'attachment_id' => $attachment_id, 'file' => $file ] = $this->make_email_with_attachment();

		( new New_Email_Local( $email, Mockery::mock( API_Interface::class ) ) )->delete_local_email_post();

		$this->assertNull( get_post( $email->get_post_id() ) );
		$this->assertNull( get_post( $attachment_id ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertCount( 0, get_comments( array( 'post_id' => $email->get_post_id() ) ) );
	}

	/**
	 * Trashing keeps attachments, files and notes, so the email can be restored intact.
	 *
	 * @covers ::delete_attachments
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Model\New_Email_Local::trash_local_email_post
	 */
	public function test_trash_keeps_attachments_and_restore_works(): void {
		[ 'email' => $email, 'attachment_id' => $attachment_id, 'file' => $file ] = $this->make_email_with_attachment();

		( new New_Email_Local( $email, Mockery::mock( API_Interface::class ) ) )->trash_local_email_post();

		$this->assertSame( 'trash', get_post_status( $email->get_post_id() ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment_id ), 'A trashed email keeps its attachment post.' );
		$this->assertFileExists( $file, 'A trashed email keeps its attachment file.' );
		// WordPress moves a trashed post's comments to the `post-trashed` status and restores them on untrash.
		$this->assertGreaterThanOrEqual(
			2,
			count(
				get_comments(
					array(
						'post_id' => $email->get_post_id(),
						'status'  => 'post-trashed',
					)
				)
			)
		);

		wp_untrash_post( $email->get_post_id() );

		$this->assertSame( 'bh_email_new', get_post_status( $email->get_post_id() ), 'Restoring returns the email to its status.' );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment_id ) );
		$this->assertFileExists( $file );
		$this->assertGreaterThanOrEqual( 2, count( get_comments( array( 'post_id' => $email->get_post_id() ) ) ), 'Log notes are restored with the email.' );
	}

	/**
	 * Only this mailbox's emails are handled: deleting a post of another type is untouched.
	 *
	 * @covers ::delete_attachments
	 */
	public function test_ignores_other_post_types(): void {
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $page,
			)
		);

		wp_delete_post( $page, true );

		$this->assertInstanceOf( WP_Post::class, get_post( $child ), 'Children of other post types are left to WordPress.' );
	}

	/**
	 * An attachment post parented to the email but missing from the `attachment_ids` meta is still removed.
	 *
	 * @covers ::get_attachment_ids
	 */
	public function test_orphaned_child_posts_are_removed_too(): void {
		[ 'email' => $email, 'attachment_id' => $attachment_id ] = $this->make_email_with_attachment();

		update_post_meta( $email->get_post_id(), 'attachment_ids', '[]' );
		$this->assertSame( $email->get_post_id(), get_post( $attachment_id )->post_parent );

		wp_delete_post( $email->get_post_id(), true );

		$this->assertNull( get_post( $attachment_id ), 'The child post is found through its parent, not the meta.' );
	}
}

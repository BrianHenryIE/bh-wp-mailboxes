<?php
/**
 * TODO: move to integration test?
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
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

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();

		$cpt->register_post_statuses();
	}

	/**
	 * @covers ::save_new
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
			provider_type_class: 'SomeProvider',
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
	 * Fetching the same email twice (same account + Message-ID, i.e. same guid) must not
	 * create two posts. The second save_new should return the already-saved post.
	 *
	 * @covers ::save_new
	 */
	public function test_save_new_dedups_by_guid(): void {

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
			provider_type_class: 'SomeProvider',
			email_address: 'test@example.com',
			display_name: 'Test Account',
			delete_local_emails_after_n_days: null,
		);

		$first  = $sut->save_new( $this->make_fetched_email( $email ), $this->settings, $email_account );
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
			provider_type_class: 'SomeProvider',
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

		// move_file_to_private_uploads() requires the `upload_files` capability.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		/** @var Private_Uploads_Settings_Interface $private_uploads_settings */
		$private_uploads_settings = new class() implements Private_Uploads_Settings_Interface {
			use Private_Uploads_Settings_Trait;

			public function get_plugin_slug(): string {
				return 'bh-wp-mailboxes-test';
			}

			public function get_uploads_subdirectory_name(): string {
				return 'bh-wp-mailboxes-test-attachments';
			}
		};
		$private_uploads          = new Private_Uploads_API( $private_uploads_settings, $this->logger );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( (string) file_get_contents( $email_filepath ), true );

		$result = $sut->save_new(
			$this->make_fetched_email( $email ),
			$this->settings,
			BH_Email_Account_Fixture::make( post_type: $post_type ),
			$private_uploads,
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
}

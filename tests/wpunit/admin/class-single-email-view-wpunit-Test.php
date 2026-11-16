<?php
/**
 * WPUnit tests for Single_Email_View.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_Settings_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\Private_Uploads_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View
 */
class Single_Email_View_WPUnit_Test extends WPUnit_Testcase {

	/** @var string CPT slug used across tests. */
	private string $post_type = 'test_mailbox_emails';

	/** @var BH_Email_Factory Fetch BH_Email instances from WP_Posts table. */
	protected BH_Email_Factory $bh_email_factory;

	/** @return BH_WP_Mailboxes_Settings_Interface&\Codeception\Stub\StubMarshaler */
	private function make_settings(): mixed {
		return $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20'          => fn() => $this->post_type,
				'get_cpt_dashed'                  => fn() => 'test-mailbox-emails',
				'get_cpt_friendly_name'           => fn() => 'Test Mailbox Emails',
				'get_configured_mailbox_settings' => fn() => array(),
			)
		);
	}

	/**
	 * Get an API instance to test.
	 *
	 * @param BH_Email_Account            $email_account_fixture Default: boring fixture with not filters configured.
	 * @param bool                        $can_return_email_account If there is a correspoinding BH_Email_Account for the BH_Email.
	 * @param ?Email_Connection_Interface $connection_mock Default: mock that supports all features.
	 */
	protected function make_api(
		?BH_Email_Account $email_account_fixture = null,
		bool $can_return_email_account = true,
		?Email_Connection_Interface $connection_mock = null,
	): API_Interface {
		$api_mock = \Mockery::mock( API_Interface::class );

		if ( $can_return_email_account ) {
			$email_account_fixture ??= BH_Email_Account_Fixture::make();
			$api_mock->allows( 'get_email_account_for_email' )->andReturn( $email_account_fixture );
		} else {
			$api_mock->allows( 'get_email_account_for_email' )->andReturnNull();
		}

		if ( ! $connection_mock ) {
			$connection_mock = \Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
			$connection_mock->expects( 'can_mark_read' )->andReturnTrue();
			$connection_mock->expects( 'can_delete_on_server' )->andReturnTrue();
			$connection_mock->expects( 'can_read_status' )->andReturnTrue();
		}
		$connection_mock->allows( 'get_friendly_name' )->andReturn( 'Test' );
		$api_mock->allows( 'get_connection_for_email_account' )->andReturn( $connection_mock );

		return $api_mock;
	}

	/**
	 * A user who may do everything: these tests cover the rendering, the capability gate has its own tests
	 * (Capability_Aware_UI_WPUnit_Test).
	 */
	private function all_capabilities(): Mailbox_Capabilities {
		/** @var Mailbox_Capabilities $capabilities */
		$capabilities = \Mockery::mock( Mailbox_Capabilities::class )->shouldIgnoreMissing( true );
		return $capabilities;
	}

	/** @return Email_WP_Post_Repository */
	private function make_repository(): Email_WP_Post_Repository {
		return new Email_WP_Post_Repository( $this->post_type, $this->get_bh_email_factory(), $this->logger );
	}

	protected function get_bh_email_factory(): BH_Email_Factory {
		if ( ! isset( $this->bh_email_factory ) ) {
			$this->bh_email_factory = new BH_Email_Factory( $this->logger );
		}
		return $this->bh_email_factory;
	}

	/** Register the CPT once per test so factory and meta operations work correctly. */
	private function register_cpt(): void {
		if ( ! post_type_exists( $this->post_type ) ) {
			register_post_type(
				$this->post_type,
				array(
					'public'  => false,
					'show_ui' => true,
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Post statuses
	// -------------------------------------------------------------------------

	/**
	 * @covers ::__construct
	 * @covers \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT::register_post_statuses
	 */
	public function test_post_statuses_are_registered_after_init(): void {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => fn() => $this->post_type,
				'get_cpt_friendly_name'  => fn() => 'Test Mailbox Emails',
			)
		);

		$cpt = new BH_Email_CPT( $settings, $this->logger );
		$cpt->register_post_statuses();

		$this->assertNotFalse( get_post_status_object( 'bh_email_new' ), 'bh_email_new should be registered' );
		$this->assertNotFalse( get_post_status_object( 'bh_email_processed' ), 'bh_email_processed should be registered' );
		$this->assertNotFalse( get_post_status_object( 'bh_email_saved' ), 'bh_email_saved should be registered' );
	}

	// -------------------------------------------------------------------------
	// Metaboxes
	// -------------------------------------------------------------------------

	/**
	 * The Email Status metabox replaces the default submitdiv.
	 *
	 * @covers ::add_meta_boxes
	 */
	public function test_add_meta_boxes_registers_email_status_box(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		// CPT must be registered before add_meta_boxes fires.
		register_post_type(
			$this->post_type,
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);

		$filepath = codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' );
		$bh_email = BH_Email_Fixture::make_from_file( $filepath, BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;

		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$registered_boxes = $wp_meta_boxes[ 'edit-' . $this->post_type ] ?? array();

		// remove_meta_box() sets entries to false rather than unsetting — filter before asserting.
		$side_high_ids = array_keys( array_filter( $registered_boxes['side']['high'] ?? array() ) );
		$this->assertContains( 'bh-email-local-status', $side_high_ids, 'Local status metabox should be in side/high' );
		$this->assertContains( 'bh-email-remote-status', $side_high_ids, 'Remote status metabox should be in side/high' );
		$this->assertNotContains( 'submitdiv', $side_high_ids, 'submitdiv should be removed' );
	}

	/**
	 * Headers metabox should always be registered.
	 *
	 * @covers ::add_meta_boxes
	 */
	public function test_add_meta_boxes_registers_headers_box(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		register_post_type(
			$this->post_type,
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		$post     = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );
		$sut->add_meta_boxes( $post );

		global $wp_meta_boxes;
		$normal_high_ids = array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['normal']['high'] ?? array() );
		$this->assertContains( 'bh-email-headers', $normal_high_ids );
	}

	/**
	 * HTML content metabox appears only when bh_email_body_html meta exists.
	 *
	 * @covers ::add_meta_boxes
	 */
	public function test_html_content_metabox_shown_only_when_html_body_present(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		// Post from plain-text-only fixture (non-multipart, no HTML part).

		$filepath        = codecept_root_dir( 'tests/_data/wpunit/non-multipart.eml' );
		$bh_email        = BH_Email_Fixture::make_from_file( $filepath, BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id_no_html = $bh_email->post_id;
		$post_no_html    = get_post( $post_id_no_html );

		// Post from HTML+plain-text fixture (has an HTML part).
		$filepath          = codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' );
		$bh_email          = BH_Email_Fixture::make_from_file( $filepath, BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id_with_html = $bh_email->post_id;
		$post_with_html    = get_post( $post_id_with_html );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		// Test without HTML.
		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post_no_html );
		$normal_default_ids_no_html = array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['normal']['default'] ?? array() );
		$this->assertNotContains( 'bh-email-content-html', $normal_default_ids_no_html );

		// Test with HTML.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post_with_html );
		$normal_default_ids_with_html = array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['normal']['default'] ?? array() );
		$this->assertContains( 'bh-email-content-html', $normal_default_ids_with_html );
	}

	// -------------------------------------------------------------------------
	// Immutability
	// -------------------------------------------------------------------------

	/**
	 * Prevent_content_edits restores original title and content for existing email posts.
	 */
	public function test_prevent_content_edits_restores_original_values(): void {

		$this->markTestSkipped( 'this functionality is in the wrong place' );

		register_post_type(
			$this->post_type,
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);

		$original_title   = 'Original Subject';
		$original_content = 'Original plain text body.';

		$post_id = $this->factory()->post->create(
			array(
				'post_type'    => $this->post_type,
				'post_title'   => $original_title,
				'post_content' => $original_content,
			)
		);

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		$incoming_data = array(
			'post_type'    => $this->post_type,
			'post_title'   => 'Attempted edit',
			'post_content' => 'Attempted content edit',
		);
		$postarr       = array( 'ID' => $post_id );

		$result = $sut->prevent_content_edits( $incoming_data, $postarr );

		$this->assertSame( $original_title, $result['post_title'] );
		$this->assertSame( $original_content, $result['post_content'] );
	}

	// -------------------------------------------------------------------------
	// Attachments metabox
	// -------------------------------------------------------------------------

	/**
	 * Save an email through the repository, with attachments saved by a real private-uploads API (files on
	 * disk), or with attachments disabled.
	 *
	 * @param string $eml_file             The fixture under tests/_data/wpunit/.
	 * @param bool   $attachments_enabled  Whether to save attachments (private uploads present).
	 */
	private function save_email_with_attachments( string $eml_file, bool $attachments_enabled = true ): BH_Email {
		$this->register_cpt();

		$parser  = new MailMimeParser();
		$message = $parser->parse( (string) file_get_contents( (string) codecept_root_dir( "tests/_data/wpunit/{$eml_file}" ) ), true );

		$email = $this->make_repository()->save_new(
			new Fetched_Email(
				$message,
				new Remote_Email_Coordinates( message_id: $message->getMessageId() ?? '' )
			),
			BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ),
			BH_Email_Account_Fixture::make(),
			$attachments_enabled ? Private_Uploads_Fixture::make( $this->logger ) : null,
		);

		$this->attachment_ids_to_clean_up = array_merge( $this->attachment_ids_to_clean_up, $email->attachment_ids ?? array() );

		return $email;
	}

	/**
	 * Attachment post ids whose files the test created; deleted in tearDown (files outlive the DB rollback).
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
	 * Render the attachments metabox for an email.
	 *
	 * @param int $post_id The email post id.
	 */
	private function render_attachments_metabox( int $post_id ): string {
		$sut  = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );
		$post = get_post( $post_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		ob_start();
		$sut->render_attachments_metabox( $post );
		return (string) ob_get_clean();
	}

	/**
	 * The ids of the metaboxes registered for this post type's screen in a context and priority.
	 *
	 * @param string $context  E.g. `side`.
	 * @param string $priority E.g. `default`.
	 *
	 * @return array<int|string>
	 */
	private function get_registered_metabox_ids( string $context, string $priority ): array {
		/**
		 * The registered metaboxes, by screen, context and priority.
		 *
		 * @var array<string, array<string, array<string, array<string, mixed>>>> $wp_meta_boxes
		 */
		global $wp_meta_boxes;

		return array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ][ $context ][ $priority ] ?? array() );
	}

	/**
	 * The attachments metabox is always registered, in the side column, so an email with no attachments says so.
	 *
	 * @covers ::add_meta_boxes
	 */
	public function test_attachments_metabox_is_registered_in_the_side_column(): void {
		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );
		$this->register_cpt();

		$email = BH_Email_Fixture::make_from_file( null, BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ), null, $this->make_repository() );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		$post = get_post( $email->post_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$this->assertContains( 'bh-email-attachments', $this->get_registered_metabox_ids( 'side', 'default' ) );
	}

	/**
	 * A saved attachment is listed as a download link to its file, which the private-uploads rewrite serves to
	 * permitted users.
	 *
	 * Regression: `wp_get_attachment_url()` returns false for the private-uploads post type, so only the
	 * filename was shown, with no link (#147).
	 *
	 * @covers ::render_attachments_metabox
	 * @covers ::get_attachment_download_url
	 */
	public function test_attachments_metabox_links_each_attachment_for_download(): void {
		$email = $this->save_email_with_attachments( 'with-attachment.eml' );
		$this->assertCount( 1, $email->attachment_ids ?? array(), 'Sanity check: the attachment was saved.' );

		$attachment_id = ( $email->attachment_ids ?? array() )[0];
		$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$this->assertIsString( $relative_path );
		$expected_url = wp_upload_dir( null, false )['baseurl'] . '/' . $relative_path;

		$html = $this->render_attachments_metabox( $email->post_id );

		$this->assertStringContainsString(
			'<a href="' . esc_url( $expected_url ) . '" download>' . basename( $relative_path ) . '</a>',
			$html
		);
	}

	/**
	 * Each segment of the link's path is URL-encoded, so a filename with non-Latin characters (which WordPress keeps) still links
	 * correctly; the filename is shown as is.
	 *
	 * @covers ::get_attachment_download_url
	 */
	public function test_attachments_metabox_link_encodes_the_filename(): void {
		$email         = $this->save_email_with_attachments( 'attachment-non-latin-filename.eml' );
		$attachment_id = ( $email->attachment_ids ?? array() )[0] ?? 0;

		$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$this->assertIsString( $relative_path );
		$filename = basename( $relative_path );
		$this->assertStringStartsWith( 'отчёт', $filename, 'Sanity check: WordPress keeps non-Latin letters in the stored filename.' );

		$html = $this->render_attachments_metabox( $email->post_id );

		$this->assertStringContainsString( '/' . rawurlencode( $filename ) . '" download>' . esc_html( $filename ) . '</a>', $html );
		$this->assertStringContainsString( '%D0%BE%D1%82%D1%87%D1%91%D1%82', $html );
	}

	/**
	 * With attachments disabled, an email that had attachments says they were discarded.
	 *
	 * @covers ::render_attachments_metabox
	 */
	public function test_attachments_metabox_says_discarded_when_attachments_were_not_saved(): void {
		$email = $this->save_email_with_attachments( 'with-attachment.eml', false );
		$this->assertNull( $email->attachment_ids, 'Sanity check: attachments disabled.' );

		$html = $this->render_attachments_metabox( $email->post_id );

		$this->assertStringContainsString( '<p class="bh-email-attachments--empty">Attachments discarded.</p>', $html );
	}

	/**
	 * An email with no attachments says so, whether attachments are enabled or not.
	 *
	 * @covers ::render_attachments_metabox
	 */
	public function test_attachments_metabox_says_none_when_there_are_none(): void {
		$enabled  = $this->save_email_with_attachments( 'html-and-plaintext.eml' );
		$disabled = $this->save_email_with_attachments( 'non-multipart.eml', false );

		$this->assertSame( array(), $enabled->attachment_ids, 'Sanity check: enabled, none saved.' );
		$this->assertNull( $disabled->attachment_ids, 'Sanity check: disabled.' );

		$this->assertStringContainsString( '<p class="bh-email-attachments--empty">No attachments.</p>', $this->render_attachments_metabox( $enabled->post_id ) );
		$this->assertStringContainsString( '<p class="bh-email-attachments--empty">No attachments.</p>', $this->render_attachments_metabox( $disabled->post_id ) );
	}

	/**
	 * An attachment whose file record is gone is listed by its post title, without a link.
	 *
	 * @covers ::render_attachments_metabox
	 * @covers ::get_attachment_download_url
	 */
	public function test_attachments_metabox_lists_an_attachment_without_a_file_by_title(): void {
		$email         = $this->save_email_with_attachments( 'with-attachment.eml' );
		$attachment_id = ( $email->attachment_ids ?? array() )[0];

		Private_Uploads_Fixture::delete_files( array( $attachment_id ) );
		delete_post_meta( $attachment_id, '_wp_attached_file' );

		$html = $this->render_attachments_metabox( $email->post_id );

		$this->assertStringContainsString( '<li>' . esc_html( get_the_title( $attachment_id ) ) . '</li>', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	// -------------------------------------------------------------------------
	// Render output: status metabox
	// -------------------------------------------------------------------------

	/**
	 * Requirement 6: "Downloaded at:" label appears in the status metabox (not "Published on").
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_downloaded_at_label(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;

		update_post_meta( $post_id, 'Date', 'Wed, 30 Jul 2025 03:38:07 +0000' );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Downloaded at:', $html, '"Downloaded at:" label should appear in the status metabox' );
		$this->assertStringNotContainsString( 'Published on', $html, '"Published on" label should not appear' );
	}

	/**
	 * Requirement 5: the visibility selector is not present in the status metabox output.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_does_not_output_visibility_section(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		$post     = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'id="visibility"', $html );
		$this->assertStringNotContainsString( 'Visibility', $html );
	}

	/**
	 * The current server status (read) is shown by highlighting its radio option.
	 *
	 * @covers ::render_remote_status_metabox
	 */
	public function test_render_remote_status_metabox_highlights_read_when_read(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		update_post_meta( $post_id, 'is_remote_read', 'yes' );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Read on server', $html );
		$this->assertMatchesRegularExpression(
			'/bh-email-status__option--current"><label><input[^>]*value="read"/',
			$html,
			'The "Read on server" radio should be highlighted as the current status.'
		);
	}

	/**
	 * The current server status (unread) is shown by highlighting its radio option.
	 *
	 * @covers ::render_remote_status_metabox
	 */
	public function test_render_remote_status_metabox_highlights_unread_when_unread(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		update_post_meta( $post_id, 'is_remote_read', 'no' );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Unread on server', $html );
		$this->assertMatchesRegularExpression(
			'/bh-email-status__option--current"><label><input[^>]*value="unread"/',
			$html,
			'The "Unread on server" radio should be highlighted as the current status.'
		);
	}

	/**
	 * Requirement 10: no remote status badge when bh_email_is_read meta is absent.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_no_remote_badge_when_connection_cannot_mark_read(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		$post     = get_post( $post_id );

		$connection_mock = \Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$connection_mock->expects( 'can_mark_read' )->andReturnFalse();
		$connection_mock->expects( 'can_delete_on_server' )->andReturnFalse();
		$connection_mock->expects( 'can_read_status' )->andReturnFalse();

		$api_mock = $this->make_api( connection_mock: $connection_mock );
		$sut      = new Single_Email_View( $this->make_settings(), $api_mock, $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'bh-email-badge--read', $html );
		$this->assertStringNotContainsString( 'bh-email-badge--unread', $html );
	}

	/**
	 * Read status is a radio select (Read/Unread on server) with a Save button when the mailbox can mark read.
	 *
	 * @covers ::render_remote_status_metabox
	 */
	public function test_render_remote_status_metabox_shows_read_status_radios(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;

		update_post_meta( $post_id, 'bh_email_is_read', '0' );
		$post = get_post( $post_id );

		$mailbox_settings = $this->makeEmpty(
			Email_Account_Settings_Interface::class,
			array(
				'get_account_display_friendly_name' => fn() => 'My Test Mailbox',
				'can_mark_read'                     => fn() => true,
				'can_delete_on_server'              => fn() => false,
			)
		);

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20'          => fn() => $this->post_type,
				'get_cpt_dashed'                  => fn() => 'test-mailbox-emails',
				'get_cpt_friendly_name'           => fn() => 'Test Mailbox Emails',
				'get_configured_mailbox_settings' => fn() => array( $mailbox_settings ),
			)
		);

		$sut = new Single_Email_View( $settings, $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="bh_email_remote_read"', $html, 'Read status should be a radio group.' );
		$this->assertStringContainsString( 'Read on server', $html );
		$this->assertStringContainsString( 'Unread on server', $html );
		$this->assertStringContainsString( 'bh-email-remote-save', $html, 'An Update button should be present for the read status.' );
		$this->assertStringContainsString( 'value="Update"', $html, 'The remote status button should be labelled "Update".' );
		$this->assertStringContainsString( 'bh-email-field__icon--read-status', $html, 'The Status label should have its icon.' );
		$this->assertStringContainsString( 'Account:', $html, 'The account name should be shown.' );
		$this->assertStringContainsString( 'Connection:', $html, 'The connection type should be shown.' );
		$this->assertStringContainsString( 'bh-email-field__icon--connection', $html, 'The Connection label should have its icon.' );
	}

	/**
	 * When the email is deleted on the server, the Status says "Deleted" and the radios are not shown.
	 *
	 * @covers ::render_remote_status_metabox
	 */
	public function test_render_remote_status_metabox_shows_deleted_when_deleted(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		update_post_meta( $post_id, 'is_remote_deleted', 'yes' );
		$post = get_post( $post_id );

		$connection_mock = \Mockery::mock( Email_Connection_Interface::class, Supports_Fetching::class );
		$connection_mock->allows( 'can_mark_read' )->andReturnTrue();
		$connection_mock->allows( 'can_delete_on_server' )->andReturnTrue();
		$connection_mock->allows( 'can_read_status' )->andReturnTrue();

		$api_mock = $this->make_api( connection_mock: $connection_mock );
		$sut      = new Single_Email_View( $this->make_settings(), $api_mock, $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		// The Status field shows "Deleted" (visible, not the hidden class) and the radios are absent.
		$this->assertStringContainsString( 'id="bh-email-remote-deleted" class="bh-email-status__deleted"', $html );
		$this->assertStringContainsString( 'Deleted', $html );
		$this->assertStringNotContainsString( 'bh-email-read-status-options', $html );
	}

	/**
	 * Requirement 11: no remote buttons shown when no mailbox is resolved (no taxonomy term on post).
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_hides_remote_buttons_when_no_mailbox_resolved(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;

		$post = get_post( $post_id );

		$api_mock = $this->make_api( can_return_email_account: false );
		$sut      = new Single_Email_View( $this->make_settings(), $api_mock, $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'bh-email-mark-read', $html );
		$this->assertStringNotContainsString( 'bh-email-mark-unread', $html );
		$this->assertStringNotContainsString( 'bh-email-delete-on-server', $html );
	}

	/**
	 * Requirement 11: delete-on-server button shown when mailbox can_delete_on_server() = true.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_delete_button_when_mailbox_can_delete(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );
		$post_id  = $bh_email->post_id;
		$post     = get_post( $post_id );

		$mailbox_settings = $this->makeEmpty(
			Email_Account_Settings_Interface::class,
			array(
				'get_account_display_friendly_name' => fn() => 'Deletable Mailbox',
				'can_mark_read'                     => fn() => false,
				'can_delete_on_server'              => fn() => true,
			)
		);

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20'          => fn() => $this->post_type,
				'get_cpt_dashed'                  => fn() => 'test-mailbox-emails',
				'get_cpt_friendly_name'           => fn() => 'Test Mailbox Emails',
				'get_configured_mailbox_settings' => fn() => array( $mailbox_settings ),
			)
		);

		$sut = new Single_Email_View( $settings, $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_remote_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bh-email-delete-on-server', $html );
	}

	/**
	 * On the email's edit screen the inline config the script reads carries the post id, the mailbox's REST
	 * root and route base, and a cookie-auth nonce.
	 *
	 * @covers ::enqueue_scripts
	 * @covers \BrianHenryIE\WP_Mailboxes\REST\REST_Namespace::url
	 */
	public function test_enqueue_scripts_adds_the_rest_config_on_the_edit_screen(): void {
		$this->register_cpt();
		$bh_email = BH_Email_Fixture::make_from_file( mailbox_settings: BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ) );

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_emails_cpt_underscored_20' => fn() => $this->post_type,
				'get_emails_cpt_dashed'         => fn() => 'test-mailbox-emails',
				'get_plugin_slug'               => fn() => 'test-plugin',
				'get_rest_namespace'            => fn() => null,
			)
		);
		$sut      = new Single_Email_View( $settings, $this->make_api(), $this->make_repository(), $this->logger );

		// The inline config is attached to core's `post` script; another test may have replaced the scripts registry.
		if ( ! wp_script_is( 'post', 'registered' ) ) {
			wp_register_script( 'post', admin_url( 'js/post.js' ), array(), '1', true );
		}

		// The single-post edit screen for this CPT, with the email as the current post.
		set_current_screen( $this->post_type );
		$GLOBALS['post'] = get_post( $bh_email->post_id );
		setup_postdata( $GLOBALS['post'] );
		try {
			$sut->enqueue_scripts();
			$after = wp_scripts()->get_data( 'post', 'after' );
		} finally {
			wp_reset_postdata();
			unset( $GLOBALS['post'] );
			set_current_screen( 'front' );
		}

		$this->assertIsArray( $after );
		$config = array_values( array_filter( $after, fn( $script ) => is_string( $script ) && str_starts_with( $script, 'var bhWpMailboxesSingleEmail = ' ) ) );
		$this->assertCount( 1, $config );
		$decoded = json_decode( substr( $config[0], strlen( 'var bhWpMailboxesSingleEmail = ' ), -1 ), true );
		$this->assertSame( $bh_email->post_id, $decoded['postId'] );
		$this->assertSame( rest_url( 'test-plugin/v2' ), $decoded['restRoot'] );
		$this->assertSame( 'test-mailbox-emails', $decoded['emailsBase'] );
		$this->assertSame( 1, wp_verify_nonce( $decoded['restNonce'], 'wp_rest' ) );
	}

	/**
	 * Nothing is enqueued away from the email edit screen.
	 *
	 * @covers ::enqueue_scripts
	 */
	public function test_enqueue_scripts_does_nothing_on_other_screens(): void {
		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		// Scripts persist across tests in the process, so compare before and after.
		$before = wp_scripts()->get_data( 'post', 'after' );
		set_current_screen( 'edit-post' );
		try {
			$sut->enqueue_scripts();
		} finally {
			set_current_screen( 'front' );
		}

		$this->assertSame( $before, wp_scripts()->get_data( 'post', 'after' ), 'Nothing added on the list screen.' );
	}
	// -------------------------------------------------------------------------
	// Thread metabox
	// -------------------------------------------------------------------------

	/**
	 * Save a thread of two emails (root + reply) into the test CPT and return them.
	 *
	 * @return array{0: BH_Email, 1: BH_Email}
	 */
	private function make_thread(): array {
		$repository = $this->make_repository();
		$settings   = BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type );

		$root_raw  = "From: customer@example.org\r\nSubject: Order 123\r\nDate: Mon, 01 Sep 2025 10:00:00 +0000\r\nMessage-ID: <root@example.org>\r\nContent-Type: text/plain\r\n\r\nHello";
		$reply_raw = "From: customer@example.org\r\nSubject: Re: Order 123\r\nDate: Mon, 01 Sep 2025 11:00:00 +0000\r\nMessage-ID: <reply@example.org>\r\nIn-Reply-To: <root@example.org>\r\nReferences: <root@example.org>\r\nContent-Type: text/plain\r\n\r\nFollowing up";

		$root  = BH_Email_Fixture::make_from_string( $root_raw, $settings, null, $repository );
		$reply = BH_Email_Fixture::make_from_string( $reply_raw, $settings, null, $repository );

		return array( $root, $reply );
	}

	/**
	 * @covers ::add_meta_boxes
	 */
	public function test_thread_metabox_absent_for_a_lone_email(): void {
		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );
		$this->register_cpt();

		$email = BH_Email_Fixture::make_from_file( null, BH_WP_Mailboxes_Settings_Fixture::make( email_cpt: $this->post_type ), null, $this->make_repository() );
		$post  = get_post( $email->post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$side_ids = array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['default'] ?? array() );
		$this->assertNotContains( 'bh-email-thread', $side_ids, 'A single email has no thread worth showing.' );
	}

	/**
	 * @covers ::add_meta_boxes
	 */
	public function test_thread_metabox_registered_when_email_has_related_emails(): void {
		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );
		$this->register_cpt();

		[ $root, $reply ] = $this->make_thread();
		$post             = get_post( $reply->post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$side_ids = array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['default'] ?? array() );
		$this->assertContains( 'bh-email-thread', $side_ids );
	}

	/**
	 * @covers ::render_thread_metabox
	 */
	public function test_thread_metabox_lists_emails_oldest_first_linking_the_others(): void {
		$this->register_cpt();
		// get_edit_post_link() returns nothing for a user who cannot edit the post.
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		[ $root, $reply ] = $this->make_thread();
		$post             = get_post( $reply->post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_thread_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bh-email-thread-list', $html );
		// Oldest first: the root precedes the reply.
		$this->assertLessThan( strpos( $html, 'data-post-id="' . $reply->post_id . '"' ), strpos( $html, 'data-post-id="' . $root->post_id . '"' ) );
		// The other email links to its edit screen; the current one is bold and unlinked.
		$this->assertStringContainsString( 'post=' . $root->post_id . '&#038;action=edit', $html );
		$this->assertStringContainsString( '<strong class="bh-email-thread-list__subject">Re: Order 123</strong>', $html );
		$this->assertStringNotContainsString( 'post=' . $reply->post_id . '&#038;action=edit', $html );
		$this->assertStringContainsString( 'bh-email-thread-list__item--current', $html );
	}
	/**
	 * A user who cannot edit the other emails sees them listed but not linked.
	 *
	 * @covers ::render_thread_metabox
	 */
	public function test_thread_metabox_does_not_link_emails_the_user_cannot_edit(): void {
		$this->register_cpt();
		wp_set_current_user( 0 );

		[ $root, $reply ] = $this->make_thread();

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger, $this->all_capabilities() );

		ob_start();
		$sut->render_thread_metabox( get_post( $reply->post_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-post-id="' . $root->post_id . '"', $html );
		$this->assertStringContainsString( '<strong class="bh-email-thread-list__subject">Order 123</strong>', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}
}

<?php
/**
 * WPUnit tests for Single_Email_View.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Codeception\Stub\Expected;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View
 */
class Single_Email_View_WPUnit_Test extends WPUnit_Testcase {

	/** @var string CPT slug used across tests. */
	private string $post_type = 'test_mailbox_emails';

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

	/** @return API_Interface */
	private function make_api(
		?BH_Email_Account $email_account_fixture = null,
		bool $can_return_email_account = true,
		?Email_Fetcher_Interface $provider_mock = null,
	): mixed {
		$api_mock = \Mockery::mock( API_Interface::class );

		$email_account_fixture ??= new BH_Email_Account(
			post_id: 1,
			post_type: $this->post_type,
			status: 'active',
			provider_type_class: ImapEngine_Imap_Email_Fetcher::class,
			email_address: 'test@example.org',
			display_name: 'Brian Henry',
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_email_action: null,
			delete_emails_after_n_days: 1,
			last_successful_login_time: null,
			last_failed_login_time: null,
		);

		if ( $can_return_email_account ) {
			$api_mock->allows( 'get_email_account_for_email' )->andReturn( $email_account_fixture );
		} else {
			$api_mock->allows( 'get_email_account_for_email' )->andReturnNull();
		}

		if ( ! $provider_mock ) {
			$provider_mock = \Mockery::mock( Email_Fetcher_Interface::class );
			$provider_mock->expects( 'can_mark_read' )->andReturnTrue();
			$provider_mock->expects( 'can_delete_on_server' )->andReturnTrue();
			$provider_mock->expects( 'can_read_status' )->andReturnTrue();
		}
		$api_mock->allows( 'get_provider_for_email_account' )->andReturn( $provider_mock );

		return $api_mock;
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

		$post_id = $this->create_post_from_fixture(
			$this->post_type,
			$filepath
		);

		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$registered_boxes = $wp_meta_boxes[ 'edit-' . $this->post_type ] ?? array();

		// remove_meta_box() sets entries to false rather than unsetting — filter before asserting.
		$side_high_ids = array_keys( array_filter( $registered_boxes['side']['high'] ?? array() ) );
		$this->assertContains( 'bh-email-status', $side_high_ids, 'Email Status metabox should be in side/high' );
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

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		$post    = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );
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
		$post_id_no_html = $this->create_post_from_fixture(
			$this->post_type,
			codecept_root_dir( 'tests/_data/wpunit/non-multipart.eml' )
		);
		$post_no_html    = get_post( $post_id_no_html );

		// Post from HTML+plain-text fixture (has an HTML part).
		$post_id_with_html = $this->create_post_from_fixture(
			$this->post_type,
			codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' )
		);
		$post_with_html    = get_post( $post_id_with_html );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

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

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

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
	// Status change logging
	// -------------------------------------------------------------------------

	/**
	 * Log_status_change inserts a comment when the post status changes.
	 */
	public function test_log_status_change_inserts_log_comment(): void {

		$this->markTestSkipped( 'this is in the wrong place' );

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

		$post_before             = get_post( $post_id );
		$post_after              = clone $post_before;
		$post_after->post_status = 'bh_email_processed';

		$api = $this->makeEmpty(
			API_Interface::class,
			array(
				'insert_email_log_note' => Expected::once(),
			)
		);

		$sut = new Single_Email_View( $this->make_settings(), $api, $this->make_repository(), $this->logger );
		$sut->log_status_change( $post_id, $post_after, $post_before );
	}

	// -------------------------------------------------------------------------
	// Attachments metabox registration
	// -------------------------------------------------------------------------

	/**
	 * Requirement 14: attachments metabox is added to the side column when attachments exist.
	 *
	 * @covers ::add_meta_boxes
	 */
	public function test_attachments_metabox_added_to_side_column_when_attachment_exists(): void {

		$this->markTestSkipped( 'Attachments metabox registration is commented out in Single_Email_View::add_meta_boxes().' );

		$this->register_cpt();

		$post_id = $this->factory()->post->create( array( 'post_type' => $this->post_type ) );
		$this->factory()->attachment->create( array( 'post_parent' => $post_id ) );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$side_ids = array_merge(
			array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['high'] ?? array() ),
			array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['default'] ?? array() ),
			array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['low'] ?? array() )
		);
		$this->assertContains( 'bh-email-attachments', $side_ids, 'Attachments metabox should be in the side column' );
	}

	/**
	 * Attachments metabox should not be registered when no attachments exist.
	 *
	 * @covers ::add_meta_boxes
	 */
	public function test_attachments_metabox_absent_when_no_attachments(): void {

		$this->markTestSkipped( 'Attachments metabox registration is commented out in Single_Email_View::add_meta_boxes().' );

		$this->register_cpt();

		$post_id = $this->factory()->post->create( array( 'post_type' => $this->post_type ) );
		$post    = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		global $wp_meta_boxes;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting before assertion is intentional in tests.
		$wp_meta_boxes = array();
		$sut->add_meta_boxes( $post );

		$all_side_ids = array_merge(
			array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['high'] ?? array() ),
			array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['default'] ?? array() ),
			array_keys( $wp_meta_boxes[ 'edit-' . $this->post_type ]['side']['low'] ?? array() )
		);
		$this->assertNotContains( 'bh-email-attachments', $all_side_ids );
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

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		update_post_meta( $post_id, 'Date', 'Wed, 30 Jul 2025 03:38:07 +0000' );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

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

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		$post    = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'id="visibility"', $html );
		$this->assertStringNotContainsString( 'Visibility', $html );
	}

	/**
	 * Requirement 10: "Read on server" badge shown when bh_email_is_read meta is truthy.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_read_badge_when_is_read_meta_set(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		update_post_meta( $post_id, 'is_remote_read', 'yes' );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bh-email-badge--read', $html );
		$this->assertStringContainsString( 'Read on server', $html );
	}

	/**
	 * Requirement 10: "Unread on server" badge shown when bh_email_is_read meta is explicitly false.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_unread_badge_when_is_read_meta_is_false(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		update_post_meta( $post_id, 'bh_email_is_read', '0' );
		$post = get_post( $post_id );

		$sut = new Single_Email_View( $this->make_settings(), $this->make_api(), $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bh-email-badge--unread', $html );
		$this->assertStringContainsString( 'Unread on server', $html );
	}

	/**
	 * Requirement 10: no remote status badge when bh_email_is_read meta is absent.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_no_remote_badge_when_provider_cannot_mark_read(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		$post    = get_post( $post_id );

		$provider_mock = \Mockery::mock( Email_Fetcher_Interface::class );
		$provider_mock->expects( 'can_mark_read' )->andReturnFalse();
		$provider_mock->expects( 'can_delete_on_server' )->andReturnFalse();
		$provider_mock->expects( 'can_read_status' )->andReturnFalse();

		$api_mock = $this->make_api( provider_mock: $provider_mock );
		$sut      = new Single_Email_View( $this->make_settings(), $api_mock, $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'bh-email-badge--read', $html );
		$this->assertStringNotContainsString( 'bh-email-badge--unread', $html );
	}

	/**
	 * Requirement 11: mark-read button shown when the resolved mailbox reports can_mark_read() = true.
	 *
	 * @covers ::render_local_status_metabox
	 */
	public function test_render_local_status_metabox_shows_mark_read_button_when_mailbox_can_mark_read(): void {

		global $current_screen;
		$current_screen = \WP_Screen::get( 'edit-' . $this->post_type );

		$this->register_cpt();

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );

		// Email is unread so the "Mark as read" button (not "Mark as unread") is rendered.
		update_post_meta( $post_id, 'bh_email_is_read', '0' );
		$post = get_post( $post_id );

		$mailbox_settings = $this->makeEmpty(
			Email_Account_Settings_Interface::class,
			array(
				'get_account_unique_friendly_name' => fn() => 'My Test Mailbox',
				'can_mark_read'                    => fn() => true,
				'can_delete_on_server'             => fn() => false,
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

		$sut = new Single_Email_View( $settings, $this->make_api(), $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bh-email-mark-read', $html, '"Mark as read on server" button should appear' );
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

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );

		$post = get_post( $post_id );

		$api_mock = $this->make_api( can_return_email_account: false );
		$sut      = new Single_Email_View( $this->make_settings(), $api_mock, $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
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

		$post_id = $this->create_post_from_fixture( post_type: $this->post_type );
		$post    = get_post( $post_id );

		$mailbox_settings = $this->makeEmpty(
			Email_Account_Settings_Interface::class,
			array(
				'get_account_unique_friendly_name' => fn() => 'Deletable Mailbox',
				'can_mark_read'                    => fn() => false,
				'can_delete_on_server'             => fn() => true,
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

		$sut = new Single_Email_View( $settings, $this->make_api(), $this->make_repository(), $this->logger );

		ob_start();
		$sut->render_local_status_metabox( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bh-email-delete-on-server', $html );
	}
}

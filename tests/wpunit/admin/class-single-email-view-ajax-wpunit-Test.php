<?php
/**
 * WPUnit tests for Single_Email_View_Ajax handlers.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface as Settings;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Fixture;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * Tests Single_Email_View_Ajax AJAX handlers.
 *
 * Extends WPUnit_Testcase (WPBrowser's WPTestCase) so the database transaction
 * lifecycle works correctly. The AJAX die-handler infrastructure from
 * WP_Ajax_UnitTestCase is inlined here to avoid setUp-chain conflicts.
 *
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View_Ajax
 */
class Single_Email_View_Ajax_WPUnit_Test extends WPUnit_Testcase {

	/** @var string CPT slug shared by all tests. */
	private string $post_type = 'test_mailbox_emails';

	/**
	 * Captured AJAX response body, equivalent to WP_Ajax_UnitTestCase::$_last_response.
	 *
	 * @var string
	 */
	protected string $_last_response = '{}';

	public function setUp(): void {
		parent::setUp();
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'getDieHandler' ), 1, 1 );
		$this->register_cpt();
	}

	public function tearDown(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'getDieHandler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// AJAX die-handler infrastructure (inlined from WP_Ajax_UnitTestCase)
	// -------------------------------------------------------------------------

	/** Returns the die handler callback registered on wp_die_ajax_handler. */
	public function getDieHandler(): callable {
		return array( $this, 'dieHandler' );
	}

	/**
	 * Captures buffered output into $_last_response, then throws an exception
	 * so the test can continue instead of the process terminating.
	 *
	 * @throws \WPAjaxDieContinueException When there is captured output.
	 * @throws \WPAjaxDieStopException     When there is no captured output.
	 *
	 * @param string|int $message The wp_die message.
	 */
	public function dieHandler( $message ): void {
		$this->_last_response .= (string) ob_get_clean();

		if ( '' === $this->_last_response ) {
			throw new \WPAjaxDieStopException( esc_html( is_scalar( $message ) ? (string) $message : '0' ) );
		}

		throw new \WPAjaxDieContinueException( esc_html( $message ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Arrange.
	 */
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

	private function make_repository(): Email_WP_Post_Repository {
		return new Email_WP_Post_Repository(
			$this->post_type,
			new BH_Email_Factory( $this->logger ),
			$this->logger
		);
	}

	private function make_sut(
		?API_Interface $api = null,
		?Email_WP_Post_Repository $email_wp_post_respository = null,
	): Single_Email_View_Ajax {
		/** @var Settings $settings */
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->makePartial();
		/** @var API_Interface $api */
		$api ??= Mockery::mock( API_Interface::class )->makePartial();
		return new Single_Email_View_Ajax(
			$settings,
			$api,
			$email_wp_post_respository ?? $this->make_repository(),
			$this->logger
		);
	}

	/**
	 * Invoke an AJAX method, capturing its JSON output in $this->_last_response.
	 *
	 * `ob_start()` opens a dedicated buffer so that dieHandler() closes *our*
	 * buffer — not Codeception's — when it calls ob_get_clean().
	 *
	 * @param callable $ajax_function_under_test The AJAX method to invoke.
	 */
	private function call_ajax( callable $ajax_function_under_test ): void {
		$this->_last_response = '';
		$level                = ob_get_level();
		ob_start();
		try {
			$ajax_function_under_test();
		} catch ( \WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Normal AJAX termination; dieHandler already closed the buffer.
		} catch ( \WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Error-only die; dieHandler already closed the buffer.
		}
		// Safety: if the callable returned without calling wp_die().
		if ( ob_get_level() > $level ) {
			$this->_last_response .= (string) ob_get_clean();
		}
	}

	/**
	 * Decode the last AJAX response captured by dieHandler.
	 *
	 * @return array{success: bool, data?: mixed} Decoded JSON.
	 */
	private function last_response(): array {
		return (array) json_decode( $this->_last_response, true );
	}

	// -------------------------------------------------------------------------
	// Input validation
	// -------------------------------------------------------------------------

	/**
	 * Missing _wpnonce must yield an error response.
	 *
	 * @covers ::handle_remote_action
	 * @covers ::ajax_mark_read
	 */
	public function test_handle_remote_action_sends_error_when_nonce_is_missing(): void {

		$_POST['post_id'] = '1';

		$sut = $this->make_sut();
		$this->call_ajax( fn() => $sut->ajax_mark_read() );

		$response = $this->last_response();
		$this->assertFalse( $response['success'] );
	}

	/**
	 * An invalid nonce must yield an error response.
	 *
	 * @covers ::handle_remote_action
	 */
	public function test_handle_remote_action_sends_error_when_nonce_is_invalid(): void {

		$_POST['_wpnonce'] = 'invalid-nonce-value';
		$_POST['post_id']  = '1';

		$sut = $this->make_sut();
		$this->call_ajax( fn() => $sut->ajax_mark_read() );

		$response = $this->last_response();
		$this->assertFalse( $response['success'] );
	}

	/**
	 * A valid nonce with a non-existent post ID must yield an error response.
	 *
	 * @covers ::handle_remote_action
	 */
	public function test_handle_remote_action_sends_error_when_post_does_not_exist(): void {

		$_POST['_wpnonce'] = wp_create_nonce( 'bh-wp-mailboxes-remote-action' );
		$_POST['post_id']  = '99999999';

		$sut = $this->make_sut();
		$this->call_ajax( fn() => $sut->ajax_mark_read() );

		$response = $this->last_response();
		$this->assertFalse( $response['success'] );
	}

	// -------------------------------------------------------------------------
	// Successful remote actions
	// -------------------------------------------------------------------------

	/**
	 * After a successful mark_read the response must include is_read: true.
	 *
	 * @covers ::__construct
	 * @covers ::handle_remote_action
	 * @covers ::ajax_mark_read
	 */
	public function test_ajax_mark_read_returns_is_read_true_in_response(): void {

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$_POST['_wpnonce'] = wp_create_nonce( 'bh-wp-mailboxes-remote-action' );
		$_POST['post_id']  = (string) $post_id;

		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'mark_email_read' )
			->once()
			->with( Mockery::type( BH_Email::class ) )
			->andReturnUsing(
				static function () use ( $bh_email ): BH_Email {
					return BH_Email_Fixture::make( is_remote_read: true, from_bh_email: $bh_email );
				}
			);

		$sut = $this->make_sut( $api );
		$this->call_ajax( fn() => $sut->ajax_mark_read() );

		$response = $this->last_response();
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['is_read'] );
		$this->assertFalse( $response['data']['is_remote_deleted'] );
	}

	/**
	 * After a successful mark_unread the response must include is_read: false.
	 *
	 * @covers ::handle_remote_action
	 * @covers ::ajax_mark_unread
	 */
	public function test_ajax_mark_unread_returns_is_read_false_in_response(): void {

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$_POST['_wpnonce'] = wp_create_nonce( 'bh-wp-mailboxes-remote-action' );
		$_POST['post_id']  = (string) $post_id;

		update_post_meta( $post_id, 'bh_email_is_read', '1' );

		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'mark_email_unread' )
			->once()
			->with( Mockery::type( BH_Email::class ) )
			->andReturnUsing(
				static function () use ( $bh_email ): BH_Email {
					return BH_Email_Fixture::make( is_remote_read: false, from_bh_email: $bh_email );
				}
			);

		$sut = $this->make_sut( $api );
		$this->call_ajax( fn() => $sut->ajax_mark_unread() );

		$response = $this->last_response();
		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['is_read'] );
		$this->assertFalse( $response['data']['is_remote_deleted'] );
	}

	/**
	 * After a successful delete the response must include is_remote_deleted: true.
	 *
	 * @covers ::handle_remote_action
	 * @covers ::ajax_delete_on_server
	 */
	public function test_ajax_delete_on_server_returns_is_remote_deleted_true(): void {

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$_POST['_wpnonce'] = wp_create_nonce( 'bh-wp-mailboxes-remote-action' );
		$_POST['post_id']  = (string) $post_id;

		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'delete_email_on_server' )
			->once()
			->with( Mockery::type( BH_Email::class ) )
			->andReturnUsing(
				static function () use ( $bh_email ): BH_Email {
					return BH_Email_Fixture::make( is_remote_deleted: true, from_bh_email: $bh_email );
				}
			);

		$sut = $this->make_sut( $api );
		$this->call_ajax( fn() => $sut->ajax_delete_on_server() );

		$response = $this->last_response();
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['is_remote_deleted'] );
	}

	/**
	 * When the API throws, the handler must return a 500 error response.
	 *
	 * @covers ::handle_remote_action
	 */
	public function test_handle_remote_action_returns_error_when_api_throws(): void {

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$_POST['_wpnonce'] = wp_create_nonce( 'bh-wp-mailboxes-remote-action' );
		$_POST['post_id']  = (string) $post_id;

		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'mark_email_read' )
			->once()
			->andThrow( new \RuntimeException( 'IMAP connection refused.' ) );

		$sut = $this->make_sut( $api );
		$this->call_ajax( fn() => $sut->ajax_mark_read() );

		$response = $this->last_response();
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'IMAP connection refused.', (string) ( $response['data']['message'] ?? '' ) );
	}

	/**
	 * When no is_read or is_remote_deleted meta is set, both values must be null.
	 *
	 * @covers ::handle_remote_action
	 */
	public function test_handle_remote_action_returns_null_values_when_meta_is_absent(): void {

		$bh_email = BH_Email_Fixture::make_from_file();
		$post_id  = $bh_email->post_id;

		$_POST['_wpnonce'] = wp_create_nonce( 'bh-wp-mailboxes-remote-action' );
		$_POST['post_id']  = (string) $post_id;

		$api = Mockery::mock( API_Interface::class );
		$api->expects( 'mark_email_read' )->once()->andReturn( $bh_email );

		$sut = $this->make_sut( $api );
		$this->call_ajax( fn() => $sut->ajax_mark_read() );

		$response = $this->last_response();
		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['is_read'] );
		$this->assertFalse( $response['data']['is_remote_deleted'] );
	}
}

<?php
/**
 * Tests for REST_Ingress_Connection.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Rest;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Repository_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Exception;
use Mockery;
use Mockery\MockInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use ZBateson\MailMimeParser\Message;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Rest\REST_Ingress_Connection
 */
class REST_Ingress_Connection_Unit_Test extends Unit_Testcase {

	/**
	 * Mocked main API.
	 *
	 * @var API_Interface&MockInterface
	 */
	protected API_Interface&MockInterface $api;

	/**
	 * Mocked plugin settings.
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface&MockInterface
	 */
	protected BH_WP_Mailboxes_Settings_Interface&MockInterface $settings;

	/**
	 * Mocked email repository.
	 *
	 * @var Email_Repository_Interface&MockInterface
	 */
	protected Email_Repository_Interface&MockInterface $email_repository;

	/**
	 * Mocked email account repository.
	 *
	 * @var Email_Account_WP_Post_Repository&MockInterface
	 */
	protected Email_Account_WP_Post_Repository&MockInterface $email_account_repository;

	/**
	 * A minimal raw MIME message with a Message-ID header.
	 */
	protected function raw_mime_with_message_id(): string {
		return "From: sender@example.com\r\nTo: recipient@example.com\r\nMessage-ID: <abc123@example.com>\r\nSubject: Test subject\r\n\r\nHello world.\r\n";
	}

	/**
	 * A minimal raw MIME message without a Message-ID header.
	 */
	protected function raw_mime_without_message_id(): string {
		return "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: No id here\r\n\r\nHello again.\r\n";
	}

	protected function make_sut( ?string $rest_namespace = 'test-ns' ): REST_Ingress_Connection {

		$this->settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->allows( 'get_rest_namespace' )->andReturn( $rest_namespace );
		$this->settings->allows( 'get_emails_cpt_dashed' )->andReturn( 'test-email' );
		$this->settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'test_email' );
		$this->settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );

		$this->api                      = Mockery::mock( API_Interface::class );
		$this->email_repository         = Mockery::mock( Email_Repository_Interface::class );
		$this->email_account_repository = Mockery::mock( Email_Account_WP_Post_Repository::class );

		return new REST_Ingress_Connection(
			$this->api,
			$this->settings,
			$this->email_repository,
			$this->email_account_repository,
			null,
			$this->logger,
		);
	}

	protected function make_account( string $email_address = 'test-ns@localhost' ): BH_Email_Account {
		return new BH_Email_Account(
			post_id: 42,
			post_type: 'test_email_account',
			local_status: 'bh_email_ac_active',
			connection_type_class: REST_Ingress_Connection::class,
			email_address: $email_address,
			display_name: 'Email REST Ingress',
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: null,
			last_checked_time: null,
			last_successful_login_time: null,
			last_failed_login_time: null,
		);
	}

	protected function make_bh_email( int $post_id = 123 ): BH_Email {
		return new BH_Email(
			post_id: $post_id,
			post_type: 'test_email',
			email_account_local_id: 42,
			imessage: Message::from( $this->raw_mime_with_message_id(), false ),
			message_id: 'abc123@example.com',
			subject: 'Test subject',
			from_email: 'sender@example.com',
		);
	}

	/**
	 * Mock wp_parse_url() to delegate to PHP's parse_url().
	 */
	protected function passthru_wp_parse_url(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Simulating wp_parse_url() in unit tests.
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component ) => parse_url( $url, $component ) );
	}

	/**
	 * A request whose body is the given raw MIME message.
	 *
	 * @param string $body         The raw MIME message.
	 * @param string $content_type The Content-Type request header.
	 */
	protected function make_request( string $body, string $content_type = 'message/rfc822' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );
		return $request;
	}

	/**
	 * @covers ::rest_init
	 */
	public function test_rest_init_does_not_register_route_when_namespace_null(): void {

		$sut = $this->make_sut( rest_namespace: null );

		\WP_Mock::userFunction( 'register_rest_route' )->never();

		$sut->rest_init();
	}

	/**
	 * @covers ::rest_init
	 * @covers ::register_rest_route
	 */
	public function test_rest_init_registers_post_route_with_permission_callback(): void {

		$sut = $this->make_sut();

		$route_args = null;

		\WP_Mock::userFunction( 'register_rest_route' )
			->once()
			->andReturnUsing(
				function ( ...$args ) use ( &$route_args ) {
					$route_args = $args;
					return true;
				}
			);

		$sut->rest_init();

		self::assertSame( 'test-ns/v2', $route_args[0] );
		self::assertSame( 'test-email/new', $route_args[1] );
		self::assertSame( 'POST', $route_args[2]['methods'] );
		self::assertIsCallable( $route_args[2]['callback'] );
		self::assertIsCallable( $route_args[2]['permission_callback'] );
	}

	/**
	 * @covers ::create_new_email_permission_callback
	 */
	public function test_permission_callback_allows_user_with_create_posts_capability(): void {

		$sut = $this->make_sut();

		$post_type_object                    = new \stdClass();
		$post_type_object->cap               = new \stdClass();
		$post_type_object->cap->create_posts = 'edit_posts';

		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'test_email' )->andReturn( $post_type_object );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		self::assertTrue( $sut->create_new_email_permission_callback() );
	}

	/**
	 * @covers ::create_new_email_permission_callback
	 */
	public function test_permission_callback_returns_wp_error_when_not_allowed(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'get_post_type_object' )->andReturn( null );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		\WP_Mock::userFunction( 'rest_authorization_required_code' )->andReturn( 401 );

		$result = $sut->create_new_email_permission_callback();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( array( 'status' => 401 ), $result->get_error_data() );
	}

	/**
	 * The account email address must be `{namespace}@{host}`, never including the URL scheme.
	 *
	 * @covers ::get_email_account_wp_post_for_mailbox
	 */
	public function test_account_email_address_is_namespace_at_host(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'site_url' )->andReturn( 'http://localhost:8888' );
		$this->passthru_wp_parse_url();

		$account = $this->make_account();

		$this->email_account_repository
			->expects( 'find_by_email_address' )
			->with( 'test-ns@localhost' )
			->andReturn( $account );

		self::assertSame( $account, $sut->get_email_account_wp_post_for_mailbox() );
	}

	/**
	 * Two concurrent first-ever requests may race: save_new() throwing "already exists" must
	 * resolve by re-fetching the account, not by failing the request.
	 *
	 * @covers ::get_email_account_wp_post_for_mailbox
	 */
	public function test_account_creation_race_returns_existing_account(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'site_url' )->andReturn( 'http://localhost:8888' );
		$this->passthru_wp_parse_url();

		$account = $this->make_account();

		$this->email_account_repository
			->expects( 'find_by_email_address' )
			->with( 'test-ns@localhost' )
			->twice()
			->andReturn( null, $account );

		$this->email_account_repository
			->expects( 'save_new' )
			->andThrow( new Exception( 'An email account already exists for test-ns@localhost.' ) );

		self::assertSame( $account, $sut->get_email_account_wp_post_for_mailbox() );
	}

	/**
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_rejects_wrong_content_type(): void {

		$sut = $this->make_sut();

		$request = $this->make_request( $this->raw_mime_with_message_id(), content_type: 'application/json' );

		$result = $sut->create_new_email( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( array( 'status' => 415 ), $result->get_error_data() );
	}

	/**
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_rejects_empty_body(): void {

		$sut = $this->make_sut();

		$result = $sut->create_new_email( $this->make_request( '' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}

	/**
	 * A repository failure is transient: 500 so the sending server retries.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_returns_500_when_save_fails(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'site_url' )->andReturn( 'http://localhost:8888' );
		$this->passthru_wp_parse_url();

		$this->email_account_repository->expects( 'find_by_email_address' )->andReturn( $this->make_account() );
		$this->email_repository->expects( 'is_post_for_message_id' )->andReturn( false );
		$this->email_repository->expects( 'save_new' )->andThrow( new Exception( 'wp_insert_post failed' ) );

		$result = $sut->create_new_email( $this->make_request( $this->raw_mime_with_message_id() ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( array( 'status' => 500 ), $result->get_error_data() );
		self::assertTrue( $this->logger->hasErrorRecords() );
	}

	/**
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_saves_and_returns_201(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'site_url' )->andReturn( 'http://localhost:8888' );
		$this->passthru_wp_parse_url();

		$this->email_account_repository->expects( 'find_by_email_address' )->andReturn( $this->make_account() );

		$this->email_repository
			->expects( 'is_post_for_message_id' )
			->with( 'test-ns@localhost', 'abc123@example.com' )
			->andReturn( false );

		$captured_fetched_email = null;

		$this->email_repository
			->expects( 'save_new' )
			->andReturnUsing(
				function ( Fetched_Email $fetched_email ) use ( &$captured_fetched_email ) {
					$captured_fetched_email = $fetched_email;
					return $this->make_bh_email( 123 );
				}
			);

		$this->api
			->expects( 'alert_new_email' )
			->andReturn( Mockery::mock( New_Email_Interface::class ) );

		$result = $sut->create_new_email( $this->make_request( $this->raw_mime_with_message_id() ) );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 201, $result->get_status() );
		self::assertSame( 123, $result->get_data()['post_id'] );
		self::assertSame( 'abc123@example.com', $result->get_data()['message_id'] );
		self::assertSame( 'abc123@example.com', $captured_fetched_email->coordinates->message_id );
		self::assertFalse( $captured_fetched_email->is_remote_read );
	}

	/**
	 * An already-saved message returns 200, not 201.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_returns_200_for_duplicate(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'site_url' )->andReturn( 'http://localhost:8888' );
		$this->passthru_wp_parse_url();

		$this->email_account_repository->expects( 'find_by_email_address' )->andReturn( $this->make_account() );
		$this->email_repository->expects( 'is_post_for_message_id' )->andReturn( true );
		$this->email_repository->expects( 'save_new' )->andReturn( $this->make_bh_email( 123 ) );

		$this->api->expects( 'alert_new_email' )->never();

		$result = $sut->create_new_email( $this->make_request( $this->raw_mime_with_message_id() ) );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 200, $result->get_status() );
		self::assertSame( 123, $result->get_data()['post_id'] );
	}

	/**
	 * Emails without a Message-ID must get a stable digest-based fallback so retries stay idempotent.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_uses_sha256_fallback_when_no_message_id(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'site_url' )->andReturn( 'http://localhost:8888' );
		$this->passthru_wp_parse_url();

		$raw_mime            = $this->raw_mime_without_message_id();
		$expected_message_id = 'sha256:' . hash( 'sha256', $raw_mime );

		$this->email_account_repository->expects( 'find_by_email_address' )->andReturn( $this->make_account() );

		$this->email_repository
			->expects( 'is_post_for_message_id' )
			->with( 'test-ns@localhost', $expected_message_id )
			->andReturn( false );

		$captured_fetched_email = null;

		$this->email_repository
			->expects( 'save_new' )
			->andReturnUsing(
				function ( Fetched_Email $fetched_email ) use ( &$captured_fetched_email ) {
					$captured_fetched_email = $fetched_email;
					return $this->make_bh_email( 124 );
				}
			);

		$this->api
			->expects( 'alert_new_email' )
			->andReturn( Mockery::mock( New_Email_Interface::class ) );

		$result = $sut->create_new_email( $this->make_request( $raw_mime ) );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 201, $result->get_status() );
		self::assertSame( $expected_message_id, $result->get_data()['message_id'] );
		self::assertSame( $expected_message_id, $captured_fetched_email->coordinates->message_id );
	}

	/**
	 * @covers ::add_email_ingress_endpoint_to_index
	 * @covers ::get_max_message_size_bytes
	 */
	public function test_add_email_ingress_endpoint_to_index_appends_entry(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'rest_url' )
			->with( 'test-ns/v2/test-email/new' )
			->andReturn( 'http://localhost:8888/wp-json/test-ns/v2/test-email/new' );
		\WP_Mock::userFunction( 'wp_convert_hr_to_bytes' )->andReturn( 8388608 );

		$response = new WP_REST_Response( array( 'existing_key' => 'existing_value' ) );

		$result = $sut->add_email_ingress_endpoint_to_index( $response );

		$data = $result->get_data();

		self::assertSame( 'existing_value', $data['existing_key'] );
		self::assertCount( 1, $data['email_ingress_endpoints'] );

		$entry = $data['email_ingress_endpoints'][0];

		self::assertSame( 1, $entry['version'] );
		self::assertSame( 'test-ns/v2', $entry['namespace'] );
		self::assertSame( 'http://localhost:8888/wp-json/test-ns/v2/test-email/new', $entry['url'] );
		self::assertSame( 'message/rfc822', $entry['accepts'] );
		self::assertSame( 8388608, $entry['max_message_size_bytes'] );
	}

	/**
	 * A second library instance advertising must append, not overwrite.
	 *
	 * @covers ::add_email_ingress_endpoint_to_index
	 */
	public function test_add_email_ingress_endpoint_to_index_preserves_existing_entries(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'rest_url' )->andReturn( 'http://localhost:8888/wp-json/test-ns/v2/test-email/new' );
		\WP_Mock::userFunction( 'wp_convert_hr_to_bytes' )->andReturn( 8388608 );

		$response = new WP_REST_Response(
			array(
				'email_ingress_endpoints' => array(
					array( 'version' => 1 ),
				),
			)
		);

		$result = $sut->add_email_ingress_endpoint_to_index( $response );

		self::assertCount( 2, $result->get_data()['email_ingress_endpoints'] );
	}

	/**
	 * When `post_max_size` is unlimited (0), the advertised size falls back to the documented default.
	 *
	 * @covers ::get_max_message_size_bytes
	 */
	public function test_max_message_size_falls_back_when_post_max_size_unlimited(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'rest_url' )->andReturn( 'http://localhost:8888/wp-json/test-ns/v2/test-email/new' );
		\WP_Mock::userFunction( 'wp_convert_hr_to_bytes' )->andReturn( 0 );

		$result = $sut->add_email_ingress_endpoint_to_index( new WP_REST_Response( array() ) );

		self::assertSame( 33554432, $result->get_data()['email_ingress_endpoints'][0]['max_message_size_bytes'] );
	}

	/**
	 * @covers ::add_email_ingress_endpoint_to_index
	 */
	public function test_add_email_ingress_endpoint_to_index_noop_when_disabled(): void {

		$sut = $this->make_sut( rest_namespace: null );

		\WP_Mock::userFunction( 'rest_url' )->never();

		$response = new WP_REST_Response( array( 'existing_key' => 'existing_value' ) );

		$result = $sut->add_email_ingress_endpoint_to_index( $response );

		self::assertArrayNotHasKey( 'email_ingress_endpoints', (array) $result->get_data() );
	}

	/**
	 * @covers ::get_friendly_name
	 */
	public function test_get_friendly_name(): void {
		self::assertSame( 'Email REST Ingress', $this->make_sut()->get_friendly_name() );
	}
}

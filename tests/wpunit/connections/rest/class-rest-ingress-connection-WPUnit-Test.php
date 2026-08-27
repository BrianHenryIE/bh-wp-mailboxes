<?php
/**
 * Tests for REST_Ingress_Connection against a real WordPress + REST server.
 *
 * Proves the worker ⇄ plugin ingress contract: POST-only route, application-password-compatible
 * auth via capability check, discovery advertisement in the REST index, idempotent saves, and
 * that the CPT REST exposure leaks nothing to unauthenticated requests.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Rest;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads_API;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Trait;
use Mockery;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Rest\REST_Ingress_Connection
 */
class REST_Ingress_Connection_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Mocked plugin settings (real CPTs are registered from it).
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * Real email repository against the test database.
	 *
	 * @var Email_WP_Post_Repository
	 */
	protected Email_WP_Post_Repository $email_repository;

	/**
	 * Real email account repository against the test database.
	 *
	 * @var Email_Account_WP_Post_Repository
	 */
	protected Email_Account_WP_Post_Repository $email_account_repository;

	protected function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = null;

		$this->settings = $this->make_settings( 'test-ns' );

		$email_cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$email_cpt->register_cpt();
		$email_cpt->register_post_statuses();

		$account_cpt = new BH_Email_Account_CPT( $this->settings, $this->logger );
		$account_cpt->register_cpt();
		$account_cpt->register_post_statuses();

		$this->email_repository         = new Email_WP_Post_Repository( 'test_email', new BH_Email_Factory( $this->logger ), $this->logger );
		$this->email_account_repository = new Email_Account_WP_Post_Repository( 'test_email_account', new BH_Email_Account_Factory( $this->logger ), $this->logger );
	}

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Mocked settings; the REST namespace is parameterized so tests can disable REST.
	 *
	 * @param ?string $rest_namespace The REST namespace, or null for REST disabled.
	 */
	protected function make_settings( ?string $rest_namespace ): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );
		$settings->allows( 'get_rest_namespace' )->andReturn( $rest_namespace );
		$settings->allows( 'get_emails_cpt_dashed' )->andReturn( 'test-email' );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'test_email' );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Email' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_email_account' );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Email Accounts' );
		return $settings;
	}

	protected function make_sut(
		?Private_Uploads_API_Interface $private_uploads = null,
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
	): REST_Ingress_Connection {
		// A real API so `alert_new_email()` genuinely fires the `bh_wp_mailboxes_new_email` action.
		$api = new API(
			$settings ?? $this->settings,
			$this->email_repository,
			$this->email_account_repository,
			new New_Email_Factory(),
			null,
			$this->logger,
		);
		return new REST_Ingress_Connection(
			$api,
			$settings ?? $this->settings,
			$this->email_repository,
			$this->email_account_repository,
			$private_uploads,
			$this->logger,
		);
	}

	/**
	 * Hook the SUT and boot the REST server (triggers rest_api_init).
	 *
	 * @param REST_Ingress_Connection $sut The connection under test.
	 */
	protected function boot_rest( REST_Ingress_Connection $sut ): void {
		add_action( 'rest_api_init', array( $sut, 'rest_init' ) );
		add_filter( 'rest_index', array( $sut, 'add_email_ingress_endpoint_to_index' ) );
		rest_get_server();
	}

	/**
	 * Dispatch a raw MIME POST to the ingress route.
	 *
	 * @param string $body         The raw MIME message.
	 * @param string $content_type The Content-Type request header.
	 */
	protected function dispatch_raw_mime( string $body, string $content_type = 'message/rfc822' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/test-ns/v2/test-email/new' );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );

		$response = rest_do_request( $request );
		self::assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	protected function login_as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A real private-uploads API writing to its own test subdirectory.
	 */
	protected function make_private_uploads(): Private_Uploads_API {

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
	 * A minimal raw MIME message without a Message-ID header.
	 *
	 * @param string $subject The email subject, varied to create distinct messages.
	 */
	protected function raw_mime_without_message_id( string $subject = 'No id here' ): string {
		return "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: {$subject}\r\n\r\nHello again.\r\n";
	}

	/**
	 * The route must exist and accept POST only. Registration must emit no _doing_it_wrong
	 * (WPTestCase fails the test on notices, covering the missing-permission_callback regression).
	 *
	 * @covers ::rest_init
	 * @covers ::register_rest_route
	 */
	public function test_route_registered_post_only(): void {

		$this->boot_rest( $this->make_sut() );

		$routes = rest_get_server()->get_routes();

		self::assertArrayHasKey( '/test-ns/v2/test-email/new', $routes );
		self::assertSame( array( 'POST' => true ), $routes['/test-ns/v2/test-email/new'][0]['methods'] );
	}

	/**
	 * @covers ::rest_init
	 */
	public function test_no_route_when_namespace_null(): void {

		$this->boot_rest( $this->make_sut( settings: $this->make_settings( null ) ) );

		self::assertArrayNotHasKey( '/test-ns/v2/test-email/new', rest_get_server()->get_routes() );
	}

	/**
	 * @covers ::create_new_email
	 * @covers ::get_email_account_wp_post_for_mailbox
	 */
	public function test_create_new_email_happy_path(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		$raw_mime = (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' ) );

		$response = $this->dispatch_raw_mime( $raw_mime );

		self::assertSame( 201, $response->get_status() );

		$post_id = $response->get_data()['post_id'];
		$email   = $this->email_repository->find_by_post_id( $post_id );
		self::assertSame( $response->get_data()['message_id'], $email->message_id );

		// The account is auto-created, filed under {namespace}@{host}, and records this connection class.
		$expected_address = 'test-ns@' . (string) wp_parse_url( site_url(), PHP_URL_HOST );
		$account          = $this->email_account_repository->find_by_email_address( $expected_address );
		self::assertNotNull( $account );
		self::assertSame( REST_Ingress_Connection::class, $account->connection_type_class );
		self::assertSame( $account->get_post_id(), get_post( $post_id )->post_parent );
	}

	/**
	 * A newly stored email is announced via `bh_wp_mailboxes_new_email` with the same signature as
	 * the fetch path; idempotent retries (duplicates) are not re-announced.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_fires_new_email_action(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		$calls = array();
		add_action(
			'bh_wp_mailboxes_new_email',
			function ( $plugin_slug, $emails_post_type, $account, $new_email ) use ( &$calls ): void {
				$calls[] = func_get_args();
			},
			10,
			4
		);

		$raw_mime = (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' ) );

		$first = $this->dispatch_raw_mime( $raw_mime );
		self::assertSame( 201, $first->get_status() );

		self::assertCount( 1, $calls );
		list( $plugin_slug, $emails_post_type, $account, $new_email ) = $calls[0];
		self::assertSame( 'test-plugin', $plugin_slug );
		self::assertSame( 'test_email', $emails_post_type );
		self::assertInstanceOf( BH_Email_Account::class, $account );
		self::assertInstanceOf( New_Email_Interface::class, $new_email );
		self::assertSame( $first->get_data()['post_id'], $new_email->get_email()->get_post_id() );

		$second = $this->dispatch_raw_mime( $raw_mime );
		self::assertSame( 200, $second->get_status() );
		self::assertCount( 1, $calls, 'A duplicate (idempotent retry) must not be re-announced.' );
	}

	/**
	 * With a real private-uploads API, the POSTed email's attachment is saved to disk.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_saves_attachments(): void {

		$this->boot_rest( $this->make_sut( private_uploads: $this->make_private_uploads() ) );
		$this->login_as_admin();

		$raw_mime = (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' ) );

		$response = $this->dispatch_raw_mime( $raw_mime );

		self::assertSame( 201, $response->get_status() );

		$email = $this->email_repository->find_by_post_id( $response->get_data()['post_id'] );

		self::assertIsArray( $email->attachment_ids );
		self::assertCount( 1, $email->attachment_ids );

		$file = get_attached_file( $email->attachment_ids[0] );
		self::assertIsString( $file );
		self::assertFileExists( $file );
		self::assertSame( "hello world\n", file_get_contents( $file ) );

		// The moved file lives outside the test DB transaction; remove it.
		wp_delete_file( $file );
	}

	/**
	 * Without a private-uploads API, the email still saves but attachments are disabled (null).
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_attachments_disabled_without_private_uploads(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		$raw_mime = (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/with-attachment.eml' ) );

		$response = $this->dispatch_raw_mime( $raw_mime );

		self::assertSame( 201, $response->get_status() );
		self::assertNull( $this->email_repository->find_by_post_id( $response->get_data()['post_id'] )->attachment_ids );
	}

	/**
	 * POSTing the same message twice must return 200 (not 201) with the same post, and store one post.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_is_idempotent(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		$raw_mime = (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' ) );

		$first  = $this->dispatch_raw_mime( $raw_mime );
		$second = $this->dispatch_raw_mime( $raw_mime );

		self::assertSame( 201, $first->get_status() );
		self::assertSame( 200, $second->get_status() );
		self::assertSame( $first->get_data()['post_id'], $second->get_data()['post_id'] );

		$expected_address = 'test-ns@' . (string) wp_parse_url( site_url(), PHP_URL_HOST );
		$account          = $this->email_account_repository->find_by_email_address( $expected_address );
		self::assertSame( 1, $this->email_repository->count_for_account_email( $account ) );
	}

	/**
	 * A message without a Message-ID gets a stable digest fallback: retries dedupe, but a
	 * different no-Message-ID message still creates a second post.
	 *
	 * @covers ::create_new_email
	 */
	public function test_create_new_email_missing_message_id_fallback(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		$first_raw = $this->raw_mime_without_message_id( 'First message' );

		$first = $this->dispatch_raw_mime( $first_raw );
		self::assertSame( 201, $first->get_status() );
		self::assertStringStartsWith( 'sha256:', $first->get_data()['message_id'] );

		$retry = $this->dispatch_raw_mime( $first_raw );
		self::assertSame( 200, $retry->get_status() );
		self::assertSame( $first->get_data()['post_id'], $retry->get_data()['post_id'] );

		$different = $this->dispatch_raw_mime( $this->raw_mime_without_message_id( 'Second message' ) );
		self::assertSame( 201, $different->get_status() );
		self::assertNotSame( $first->get_data()['post_id'], $different->get_data()['post_id'] );
	}

	/**
	 * @covers ::create_new_email_permission_callback
	 */
	public function test_unauthenticated_post_is_401(): void {

		$this->boot_rest( $this->make_sut() );
		wp_set_current_user( 0 );

		$response = $this->dispatch_raw_mime( $this->raw_mime_without_message_id() );

		self::assertSame( 401, $response->get_status() );
	}

	/**
	 * @covers ::create_new_email_permission_callback
	 */
	public function test_subscriber_post_is_403(): void {

		$this->boot_rest( $this->make_sut() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->dispatch_raw_mime( $this->raw_mime_without_message_id() );

		self::assertSame( 403, $response->get_status() );
	}

	/**
	 * @covers ::create_new_email
	 */
	public function test_wrong_content_type_is_415(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		// Note: `application/json` would be rejected by WP core's JSON parsing (400) before the
		// callback runs; `text/plain` reaches the callback's own content-type check.
		$response = $this->dispatch_raw_mime( $this->raw_mime_without_message_id(), content_type: 'text/plain' );

		self::assertSame( 415, $response->get_status() );
	}

	/**
	 * The REST index must advertise the endpoint to unauthenticated readers (the worker discovers
	 * before it authenticates).
	 *
	 * @covers ::add_email_ingress_endpoint_to_index
	 */
	public function test_rest_index_advertises_ingress_endpoint(): void {

		$this->boot_rest( $this->make_sut() );
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/' ) );

		self::assertSame( 200, $response->get_status() );

		$data = (array) $response->get_data();

		self::assertArrayHasKey( 'email_ingress_endpoints', $data );

		// The development plugin (loaded by the test bootstrap) advertises its own entry; assert on ours.
		$test_ns_entries = array_values(
			array_filter(
				$data['email_ingress_endpoints'],
				fn( array $entry ): bool => 'test-ns/v2' === $entry['namespace']
			)
		);
		self::assertCount( 1, $test_ns_entries );

		$entry = $test_ns_entries[0];

		self::assertSame( 1, $entry['version'] );
		self::assertSame( 'test-ns/v2', $entry['namespace'] );
		self::assertStringEndsWith( '/test-ns/v2/test-email/new', $entry['url'] );
		self::assertSame( 'message/rfc822', $entry['accepts'] );
		self::assertIsInt( $entry['max_message_size_bytes'] );
		self::assertGreaterThan( 0, $entry['max_message_size_bytes'] );
	}

	/**
	 * `show_in_rest` on the CPTs must not leak stored emails or account configuration to
	 * unauthenticated requests: emails use custom (non-public) post statuses.
	 */
	public function test_cpt_rest_routes_leak_nothing_to_unauthenticated_requests(): void {

		$this->boot_rest( $this->make_sut() );
		$this->login_as_admin();

		// Store an email (and thereby an account).
		$response = $this->dispatch_raw_mime( (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' ) ) );
		self::assertSame( 201, $response->get_status() );

		wp_set_current_user( 0 );

		foreach ( array( 'test_email', 'test_email_account' ) as $rest_base ) {

			$list_response = rest_do_request( new WP_REST_Request( 'GET', "/test-ns/v2/{$rest_base}" ) );
			self::assertSame( 200, $list_response->get_status() );
			self::assertSame( array(), $list_response->get_data(), "Unauthenticated GET /{$rest_base} must return an empty list." );

			$status_request = new WP_REST_Request( 'GET', "/test-ns/v2/{$rest_base}" );
			$status_request->set_param( 'status', 'test_email' === $rest_base ? 'bh_email_new' : 'bh_email_ac_active' );
			$status_response = rest_do_request( $status_request );
			self::assertContains(
				$status_response->get_status(),
				array( 400, 401, 403 ),
				"Unauthenticated status query on /{$rest_base} must be rejected."
			);
		}
	}

	/**
	 * Cron regression: the API must know how to construct this connection for its stored account,
	 * so the fetch loop skips it quietly instead of warning "No email fetcher found" every run.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\API::get_connection_for_email_account
	 */
	public function test_api_constructs_rest_ingress_connection_for_account(): void {

		$api = new API(
			$this->settings,
			$this->email_repository,
			$this->email_account_repository,
			new New_Email_Factory(),
			null,
			$this->logger,
		);

		$account = BH_Email_Account_Fixture::make(
			post_type: 'test_email_account',
			connection_type_class: REST_Ingress_Connection::class,
		);

		$connection = $api->get_connection_for_email_account( $account );

		self::assertInstanceOf( REST_Ingress_Connection::class, $connection );
		self::assertFalse( $this->logger->hasWarningRecords(), 'No "No email fetcher found" warning should be logged.' );
	}
}

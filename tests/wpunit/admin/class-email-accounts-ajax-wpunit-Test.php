<?php
/**
 * WPUnit tests for the accounts table's save handler.
 *
 * Covers `save()`: the account upsert, saving the credentials to the credentials store (the Secrets
 * API, loaded from vendor), password retention on edit, and validation; plus the `handle_*()` wrappers'
 * error responses.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Secrets_API_Loader;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use InvalidArgumentException;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Email_Accounts_Ajax
 */
class Email_Accounts_Ajax_WPUnit_Test extends WPUnit_Testcase {

	const ACCOUNTS_CPT = 'test_eaa_accounts';
	const EMAILS_CPT   = 'test_eaa_emails';

	/**
	 * The API under test, so tests can read back what was saved to the credentials store.
	 *
	 * @var API
	 */
	protected API $api;

	/**
	 * The real accounts repository behind the API under test.
	 *
	 * @var Email_Account_WP_Post_Repository
	 */
	protected Email_Account_WP_Post_Repository $account_repository;

	public function setUp(): void {
		parent::setUp();

		$this->assertTrue( Secrets_API_Loader::load(), 'The Secrets API feature plugin should load from vendor.' );

		// Never connect to a real IMAP server: a connection that requires credentials and always connects.
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class );
				$connection->allows( 'set_credentials' );
				$connection->allows( 'test_connection' )->andReturn( true );
				return $connection;
			}
		);
	}

	public function tearDown(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		parent::tearDown();
	}

	protected function make_sut(): Email_Accounts_Ajax {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( self::EMAILS_CPT );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( self::ACCOUNTS_CPT );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Accounts' );
		$settings->allows( 'get_rest_namespace' )->andReturn( null );

		$cpt = new BH_Email_Account_CPT( $settings, $this->logger );
		$cpt->register_cpt();
		$cpt->register_post_statuses();

		$this->account_repository = new Email_Account_WP_Post_Repository( self::ACCOUNTS_CPT, new BH_Email_Account_Factory( $this->logger ), $this->logger );

		$email_repository = Mockery::mock( Email_WP_Post_Repository::class );
		$email_repository->allows( 'save_all' )->andReturn( array() );

		$this->api = new API(
			$settings,
			$email_repository,
			$this->account_repository,
			new New_Email_Factory(),
			null,
			$this->logger,
		);

		$status_view = Mockery::mock( Status_View::class );
		$status_view->allows( 'render_table' )->andReturnUsing(
			function () {
				echo '<table class="wp-list-table"></table>';
			}
		);

		return new Email_Accounts_Ajax( $this->api, $settings, $status_view, $this->logger );
	}

	/**
	 * Saving a new account creates an IMAP account post and saves the credentials (username defaulting
	 * to the address) to the credentials store, then tests the connection.
	 *
	 * @covers ::save
	 */
	public function test_save_creates_account_and_fires_credentials_action(): void {
		$sut = $this->make_sut();

		$result = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com:993', '', 'p<a&ss"word', 'STARTTLS' );

		$this->assertTrue( $result->created );
		$this->assertTrue( $result->connection->success );
		$this->assertSame( 'Inbox', $result->account->display_name );
		$this->assertSame( ImapEngine_Imap_Email_Connection::class, $result->account->connection_type_class );
		$this->assertNotNull( $this->account_repository->find_by_email_address( 'inbox@example.com' ) );

		$credentials = $this->api->get_account_credentials( $result->account );
		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $credentials );
		$this->assertSame( 'imap.example.com:993', $credentials->get_email_imap_server() );
		$this->assertSame( 'inbox@example.com', $credentials->get_email_account_username(), 'Username defaults to the email address.' );
		$this->assertSame( 'p<a&ss"word', $credentials->get_email_account_password(), 'The password is passed verbatim.' );
		$this->assertSame( 'STARTTLS', $credentials->get_encryption() );
		$this->assertTrue( $credentials->should_validate_cert(), 'Certificates are validated unless told otherwise.' );
	}

	/**
	 * The validate-certificate choice is saved with the credentials: explicitly off via the API, and
	 * from the modal's POST as `validate_cert=0`; an old caller that does not post it gets the default.
	 *
	 * @covers ::save
	 * @covers ::handle_save
	 * @covers ::post_bool
	 */
	public function test_save_persists_validate_cert(): void {
		$sut = $this->make_sut();

		$result = $sut->save( 'selfsigned@example.com', 'Self-signed', 'imap.internal', '', 'secret', 'TLS', false );
		$this->assertFalse( $this->api->get_account_credentials( $result->account )?->should_validate_cert() );

		$response = $this->run_handler(
			array( $sut, 'handle_save' ),
			array(
				'email_address' => 'posted-off@example.com',
				'server'        => 'imap.internal',
				'password'      => 'secret',
				'encryption'    => 'TLS',
				'validate_cert' => '0',
			)
		);
		$this->assertTrue( $response['success'] );
		$account = $this->account_repository->find_by_email_address( 'posted-off@example.com' );
		$this->assertFalse( $this->api->get_account_credentials( $account )?->should_validate_cert() );

		$response = $this->run_handler(
			array( $sut, 'handle_save' ),
			array(
				'email_address' => 'not-posted@example.com',
				'server'        => 'imap.example.com',
				'password'      => 'secret',
				'encryption'    => 'TLS',
			)
		);
		$this->assertTrue( $response['success'] );
		$account = $this->account_repository->find_by_email_address( 'not-posted@example.com' );
		$this->assertTrue( $this->api->get_account_credentials( $account )?->should_validate_cert(), 'Absent from the POST means the default.' );
	}

	/**
	 * Saving an existing address updates the account in place; an empty password keeps the saved one.
	 *
	 * @covers ::save
	 * @covers ::validate
	 */
	public function test_save_updates_existing_account_and_keeps_password_when_blank(): void {
		$sut = $this->make_sut();

		$created = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'original-password' );

		$updated = $sut->save( 'inbox@example.com', 'Renamed', 'imap.example.com:143', 'user', '', '' );

		$this->assertFalse( $updated->created );
		$this->assertSame( $created->account->get_post_id(), $updated->account->get_post_id() );
		$this->assertSame( 'Renamed', $updated->account->display_name );
		$this->assertCount( 1, $this->account_repository->get_all() );

		$credentials = $this->api->get_account_credentials( $updated->account );
		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $credentials );
		$this->assertSame( 'imap.example.com:143', $credentials->get_email_imap_server() );
		$this->assertSame( 'user', $credentials->get_email_account_username() );
		$this->assertSame( 'original-password', $credentials->get_email_account_password() );
		$this->assertSame( '', $credentials->get_encryption() );
	}

	/**
	 * Invalid input throws before anything is saved or announced.
	 *
	 * @covers ::save
	 * @covers ::validate
	 */
	public function test_save_validates_input(): void {
		$sut = $this->make_sut();

		$cases = array(
			'invalid email'      => array( 'not-an-email', 'imap.example.com', 'secret', 'TLS', 'valid email address' ),
			'missing server'     => array( 'inbox@example.com', '', 'secret', 'TLS', 'IMAP server is required' ),
			'missing password'   => array( 'inbox@example.com', 'imap.example.com', '', 'TLS', 'password is required' ),
			'unknown encryption' => array( 'inbox@example.com', 'imap.example.com', 'secret', 'SSL', 'Encryption must be' ),
		);

		foreach ( $cases as $name => [ $email, $server, $password, $encryption, $expected_message ] ) {
			try {
				$sut->save( $email, 'Inbox', $server, '', $password, $encryption );
				$this->fail( "Expected an InvalidArgumentException for: {$name}" );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertStringContainsString( $expected_message, $exception->getMessage(), $name );
			}
		}

		$this->assertCount( 0, $this->account_repository->get_all(), 'No account should be created.' );
	}

	/**
	 * Validation messages are plain text: the JSON response is rendered as text by the JS, so they
	 * must not be HTML-escaped.
	 *
	 * @covers ::save
	 * @covers ::validate
	 */
	public function test_save_validation_message_is_not_html_escaped(): void {
		$sut = $this->make_sut();

		try {
			$sut->save( "o'brien@example", 'Inbox', '', '', '', 'TLS' );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertStringNotContainsString( '&#039;', $exception->getMessage() );
			$this->assertStringNotContainsString( '&amp;', $exception->getMessage() );
		}
	}

	/**
	 * The upsert is keyed by email address, so saving the address of an account with another connection
	 * (e.g. the REST ingress) must be refused rather than converting it into an IMAP account.
	 *
	 * @covers ::save
	 * @covers ::validate
	 */
	public function test_save_refuses_to_convert_non_imap_account(): void {
		$sut = $this->make_sut();

		$this->account_repository->save_new(
			email_address: 'ingress@example.com',
			display_name: 'Ingress',
			connection_type_class: 'Some\Other\Connection',
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: null,
		);

		try {
			$sut->save( 'ingress@example.com', 'Hijacked', 'imap.example.com', '', 'secret', 'TLS' );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'not an IMAP account', $exception->getMessage() );
		}

		$account = $this->account_repository->find_by_email_address( 'ingress@example.com' );
		$this->assertNotNull( $account );
		$this->assertSame( 'Some\Other\Connection', $account->connection_type_class, 'The connection class must be unchanged.' );
		$this->assertSame( 'Ingress', $account->display_name );
		$this->assertNull( $this->api->get_account_credentials( $account ), 'No credentials should be saved.' );
	}

	/**
	 * Run a `handle_*()` method as an admin with a valid nonce, capturing the JSON it sends before it
	 * dies (wp-browser's `wp_die` handler throws WPDieException).
	 *
	 * @param callable             $handler The handler to call.
	 * @param array<string,string> $post The POST fields.
	 *
	 * @return array{success:bool, data:array<string,mixed>}
	 */
	protected function run_handler( callable $handler, array $post ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST    = array_merge( array( '_wpnonce' => wp_create_nonce( Email_Accounts_Ajax::NONCE_ACTION ) ), $post );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Arranging the request the handler verifies.

		// wp_send_json_*() only calls wp_die() (rather than die) during AJAX; make wp_die() throw so the test continues.
		$doing_ajax  = '__return_true';
		$die_handler = function () {
			return function (): void {
				throw new \WPDieException();
			};
		};
		add_filter( 'wp_doing_ajax', $doing_ajax );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		$died = false;
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $exception ) {
			$died = true;
		} finally {
			$output   = (string) ob_get_clean();
			$_POST    = array();
			$_REQUEST = array();
			remove_filter( 'wp_doing_ajax', $doing_ajax );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
			wp_set_current_user( 0 );
		}
		$this->assertTrue( $died, 'Expected the handler to send JSON and die.' );

		return json_decode( $output, true );
	}

	/**
	 * "Check now" is refused for accounts whose connection does not fetch (e.g. the REST ingress).
	 *
	 * @covers ::handle_check
	 */
	public function test_check_receive_only_account_is_refused(): void {
		$sut = $this->make_sut();
		// The connection mocked in setUp() requires credentials but does not implement Supports_Fetching.
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'secret', 'TLS' );

		$response = $this->run_handler( array( $sut, 'handle_check' ), array( 'account_post_id' => (string) $saved->account->get_post_id() ) );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'does not fetch email', $response['data']['message'] );
	}

	/**
	 * "Test connection" tries the entered details against the server without creating the account or
	 * saving the credentials.
	 *
	 * @covers ::test_connection
	 * @covers ::handle_test_connection
	 * @covers ::validate
	 */
	public function test_test_connection_reports_success_without_saving(): void {
		$sut = $this->make_sut();

		$response = $this->run_handler(
			array( $sut, 'handle_test_connection' ),
			array(
				'email_address' => 'new@example.com',
				'display_name'  => 'New',
				'server'        => 'imap.example.com',
				'password'      => 'secret',
				'encryption'    => 'TLS',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Connected successfully.', $response['data']['message'] );

		$this->assertNull( $this->account_repository->find_by_email_address( 'new@example.com' ), 'Testing must not create the account.' );
	}

	/**
	 * A refused login is reported as a failed test (HTTP 200, success false, the server's message),
	 * so the form can show it without treating it as a request error.
	 *
	 * @covers ::test_connection
	 * @covers ::handle_test_connection
	 */
	public function test_test_connection_reports_the_servers_error(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		$credentials_seen = null;
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () use ( &$credentials_seen ) {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class );
				$connection->allows( 'set_credentials' )->andReturnUsing(
					function ( $credentials ) use ( &$credentials_seen ) {
						$credentials_seen = $credentials;
					}
				);
				$connection->allows( 'test_connection' )->andThrow( new \Exception( 'AUTHENTICATIONFAILED' ) );
				return $connection;
			}
		);

		$sut = $this->make_sut();

		$response = $this->run_handler(
			array( $sut, 'handle_test_connection' ),
			array(
				'email_address' => 'new@example.com',
				'server'        => 'imap.example.com:993',
				'username'      => 'user',
				'password'      => 'wrong',
				'encryption'    => 'STARTTLS',
				'validate_cert' => '0',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'AUTHENTICATIONFAILED', $response['data']['message'] );

		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $credentials_seen );
		$this->assertSame( 'imap.example.com:993', $credentials_seen->get_email_imap_server() );
		$this->assertSame( 'user', $credentials_seen->get_email_account_username() );
		$this->assertSame( 'wrong', $credentials_seen->get_email_account_password() );
		$this->assertSame( 'STARTTLS', $credentials_seen->get_encryption() );
		$this->assertFalse( $credentials_seen->should_validate_cert(), 'The test uses the posted certificate choice.' );
	}

	/**
	 * When editing, a blank password tests with the saved one, and the saved credentials are left as they were.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_uses_the_saved_password_when_editing_with_a_blank_one(): void {
		$sut   = $this->make_sut();
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'original-password' );

		$credentials_seen = null;
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () use ( &$credentials_seen ) {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class );
				$connection->allows( 'set_credentials' )->andReturnUsing(
					function ( $credentials ) use ( &$credentials_seen ) {
						$credentials_seen = $credentials;
					}
				);
				$connection->allows( 'test_connection' )->andReturn( true );
				return $connection;
			}
		);

		$result = $sut->test_connection( 'inbox@example.com', 'Inbox', 'imap.other.com', 'other-user', '', 'TLS' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'imap.other.com', $credentials_seen->get_email_imap_server() );
		$this->assertSame( 'original-password', $credentials_seen->get_email_account_password() );

		$still_saved = $this->api->get_account_credentials( $saved->account );
		$this->assertSame( 'imap.example.com', $still_saved->get_email_imap_server(), 'Testing must not overwrite the saved credentials.' );
	}

	/**
	 * Editing an account that has no saved credentials cannot fall back to a saved password, so a
	 * blank one is refused.
	 *
	 * @covers ::test_connection
	 * @covers ::validate
	 */
	public function test_test_connection_requires_a_password_when_editing_an_account_without_credentials(): void {
		$sut = $this->make_sut();
		$this->account_repository->save_new( 'nocreds@example.com', 'No creds', ImapEngine_Imap_Email_Connection::class, null, null, null, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The password is required.' );

		$sut->test_connection( 'nocreds@example.com', 'No creds', 'imap.example.com', '', '', 'TLS' );
	}

	/**
	 * Invalid input is refused with the same validation as saving.
	 *
	 * @covers ::handle_test_connection
	 * @covers ::validate
	 */
	public function test_test_connection_validates_input(): void {
		$sut = $this->make_sut();

		$response = $this->run_handler(
			array( $sut, 'handle_test_connection' ),
			array(
				'email_address' => 'not-an-email',
				'server'        => '',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'A valid email address is required.', $response['data']['message'] );
		$this->assertStringContainsString( 'The IMAP server is required.', $response['data']['message'] );
		$this->assertStringContainsString( 'The password is required.', $response['data']['message'] );
	}

	/**
	 * "Check now" against a server that rejects the login (or is unreachable) reports the failure,
	 * with the connection's message, and records the failed-login time on the account.
	 *
	 * @covers ::handle_check
	 */
	public function test_check_reports_a_connection_failure(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class, Supports_Fetching::class );
				$connection->allows( 'set_credentials' );
				$connection->allows( 'test_connection' )->andReturn( true );
				$connection->allows( 'retrieve_emails' )->andThrow( new \Exception( 'Can not authenticate to IMAP server: AUTHENTICATIONFAILED' ) );
				return $connection;
			}
		);

		$sut   = $this->make_sut();
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'wrong-password', 'TLS' );

		$response = $this->run_handler( array( $sut, 'handle_check' ), array( 'account_post_id' => (string) $saved->account->get_post_id() ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'failed', $response['data']['status'] );
		$this->assertSame( 'Could not fetch emails: Can not authenticate to IMAP server: AUTHENTICATIONFAILED', $response['data']['message'] );
		$this->assertStringContainsString( '<table', $response['data']['table_html'] );

		$account = $this->account_repository->find_by_post_id( $saved->account->get_post_id() );
		$this->assertNotNull( $account->last_failed_login_time );
		$this->assertNull( $account->last_successful_login_time );
	}

	/**
	 * "Check now" on an account with no saved credentials fails with a message saying so.
	 *
	 * @covers ::handle_check
	 */
	public function test_check_reports_missing_credentials(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class, Supports_Fetching::class );
				$connection->shouldNotReceive( 'retrieve_emails' );
				return $connection;
			}
		);

		$sut     = $this->make_sut();
		$account = $this->account_repository->save_new( 'nocreds@example.com', 'No creds', ImapEngine_Imap_Email_Connection::class, null, null, null, null );

		$response = $this->run_handler( array( $sut, 'handle_check' ), array( 'account_post_id' => (string) $account->get_post_id() ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'failed', $response['data']['status'] );
		$this->assertSame( 'No credentials are saved for this account.', $response['data']['message'] );
	}

	/**
	 * "Check now" on a disabled account is reported as skipped, not as a failure.
	 *
	 * @covers ::handle_check
	 */
	public function test_check_reports_a_disabled_account_as_skipped(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class, Supports_Fetching::class );
				$connection->allows( 'set_credentials' );
				$connection->allows( 'test_connection' )->andReturn( true );
				$connection->shouldNotReceive( 'retrieve_emails' );
				return $connection;
			}
		);

		$sut   = $this->make_sut();
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'secret', 'TLS' );
		$this->api->set_email_account_active( 'inbox@example.com', false );

		$response = $this->run_handler( array( $sut, 'handle_check' ), array( 'account_post_id' => (string) $saved->account->get_post_id() ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'skipped', $response['data']['status'] );
		$this->assertSame( 'The account is disabled.', $response['data']['message'] );
	}

	/**
	 * A successful "Check now" reports the count and ids of the new emails.
	 *
	 * @covers ::handle_check
	 */
	public function test_check_reports_success_with_no_new_emails(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class, Supports_Fetching::class );
				$connection->allows( 'set_credentials' );
				$connection->allows( 'test_connection' )->andReturn( true );
				$connection->allows( 'retrieve_emails' )->andReturn( new \Illuminate\Support\Collection() );
				return $connection;
			}
		);

		$sut   = $this->make_sut();
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'secret', 'TLS' );

		$response = $this->run_handler( array( $sut, 'handle_check' ), array( 'account_post_id' => (string) $saved->account->get_post_id() ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $response['data']['new_email_count'] );
		$this->assertSame( array(), $response['data']['new_email_ids'] );
		$this->assertSame( array(), $response['data']['warnings'] );

		$account = $this->account_repository->find_by_post_id( $saved->account->get_post_id() );
		$this->assertNotNull( $account->last_successful_login_time );
	}

	/**
	 * Enabling/disabling reports "not found" when the API cannot find the account.
	 *
	 * @covers ::handle_set_active
	 */
	public function test_set_active_unknown_account_is_not_found(): void {
		$account = new BH_Email_Account( 123, self::ACCOUNTS_CPT, 'bh_email_ac_active', ImapEngine_Imap_Email_Connection::class, 'gone@example.com', 'Gone', null, null, null, null, null, null, null );

		$api = Mockery::mock( API::class );
		$api->allows( 'get_email_accounts' )->andReturn( array( $account ) );
		$api->allows( 'set_email_account_active' )->with( 'gone@example.com', false )->andReturn( null );

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$sut      = new Email_Accounts_Ajax( $api, $settings, Mockery::mock( Status_View::class ), $this->logger );

		$response = $this->run_handler(
			array( $sut, 'handle_set_active' ),
			array(
				'account_post_id' => '123',
				'active'          => '0',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Account not found.', $response['data']['message'] );
	}
}

<?php
/**
 * WPUnit tests for the accounts table's save handler.
 *
 * The `handle_*()` wrappers call wp_send_json_*() (which exits) and are covered by Playwright; these
 * tests cover `save()`: the account upsert, the credentials action for the consumer, password
 * retention on edit, and validation.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\New_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
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
	 * Credentials handed to the consumer, captured from the action.
	 *
	 * @var array<int, array{0:string, 1:string, 2:BH_Email_Account, 3:Account_Credentials_Interface}>
	 */
	protected array $saved_credentials = array();

	/**
	 * What the consumer answers for `bh_wp_mailboxes_credentials`.
	 *
	 * @var ?IMAP_Credentials_Interface
	 */
	protected ?IMAP_Credentials_Interface $consumer_credentials = null;

	/**
	 * The real accounts repository behind the API under test.
	 *
	 * @var Email_Account_WP_Post_Repository
	 */
	protected Email_Account_WP_Post_Repository $account_repository;

	public function setUp(): void {
		parent::setUp();

		$this->saved_credentials    = array();
		$this->consumer_credentials = null;

		add_action(
			'bh_wp_mailboxes_save_account_credentials',
			function ( string $plugin_slug, string $emails_post_type, BH_Email_Account $account, Account_Credentials_Interface $credentials ): void {
				$this->saved_credentials[] = array( $plugin_slug, $emails_post_type, $account, $credentials );
			},
			10,
			4
		);
		add_filter( 'bh_wp_mailboxes_credentials', fn( $value ) => $this->consumer_credentials ?? $value );

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
		remove_all_actions( 'bh_wp_mailboxes_save_account_credentials' );
		remove_all_filters( 'bh_wp_mailboxes_credentials' );
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

		$api = new API(
			$settings,
			Mockery::mock( Email_WP_Post_Repository::class ),
			$this->account_repository,
			new New_Email_Factory(),
			null,
			$this->logger,
		);

		$status_view = Mockery::mock( Status_View::class );

		return new Email_Accounts_Ajax( $api, $settings, $status_view, $this->logger );
	}

	/**
	 * Saving a new account creates an IMAP account post and hands the credentials (username defaulting
	 * to the address) to the consumer via the action, then tests the connection.
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

		$this->assertCount( 1, $this->saved_credentials );
		[ $plugin_slug, $emails_post_type, $account, $credentials ] = $this->saved_credentials[0];
		$this->assertSame( 'test-plugin', $plugin_slug );
		$this->assertSame( self::EMAILS_CPT, $emails_post_type );
		$this->assertSame( $result->account->get_post_id(), $account->get_post_id() );
		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $credentials );
		$this->assertSame( 'imap.example.com:993', $credentials->get_email_imap_server() );
		$this->assertSame( 'inbox@example.com', $credentials->get_email_account_username(), 'Username defaults to the email address.' );
		$this->assertSame( 'p<a&ss"word', $credentials->get_email_account_password(), 'The password is passed verbatim.' );
		$this->assertSame( 'STARTTLS', $credentials->get_encryption() );
	}

	/**
	 * Saving an existing address updates the account in place; an empty password keeps the one the
	 * consumer already holds (read through the credentials filter).
	 *
	 * @covers ::save
	 */
	public function test_save_updates_existing_account_and_keeps_password_when_blank(): void {
		$sut = $this->make_sut();

		$created = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'original-password' );

		$this->consumer_credentials = new Imap_Credentials( 'imap.example.com', 'inbox@example.com', 'original-password' );

		$updated = $sut->save( 'inbox@example.com', 'Renamed', 'imap.example.com:143', 'user', '', '' );

		$this->assertFalse( $updated->created );
		$this->assertSame( $created->account->get_post_id(), $updated->account->get_post_id() );
		$this->assertSame( 'Renamed', $updated->account->display_name );
		$this->assertCount( 1, $this->account_repository->get_all() );

		$credentials = $this->saved_credentials[1][3];
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
		$this->assertCount( 0, $this->saved_credentials, 'No credentials should be announced.' );
	}

	/**
	 * Validation messages are plain text: the JSON response is rendered as text by the JS, so they
	 * must not be HTML-escaped.
	 *
	 * @covers ::save
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
		$this->assertCount( 0, $this->saved_credentials, 'No credentials should be announced.' );
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

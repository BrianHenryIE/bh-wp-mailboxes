<?php
/**
 * WPUnit tests for saving and testing IMAP accounts from the form's fields.
 *
 * Covers `save()`: the account upsert, saving the credentials to the credentials store (the Secrets
 * API, loaded from vendor), password retention on edit, and validation; and `test_connection()`: no
 * side effects, the entered credentials reaching the connection, and the saved-password fallback.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Controller\Email_Controller_Factory;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Secrets_API_Loader;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use InvalidArgumentException;
use Mockery;

/**
 * Every test exercises the manager end to end, so the whole class is credited (class-level `@covers`);
 * the per-method tags name what each test is about.
 *
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Manager
 * @covers \BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Manager
 */
class Email_Account_Manager_WPUnit_Test extends WPUnit_Testcase {

	const ACCOUNTS_CPT = 'test_eam_accounts';
	const EMAILS_CPT   = 'test_eam_emails';

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
		$this->use_connection( true );
	}

	public function tearDown(): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		parent::tearDown();
	}

	/**
	 * Resolve every account to a mocked IMAP-style connection.
	 *
	 * @param bool|\Throwable $test_result What `test_connection()` returns, or throws.
	 * @param ?callable       $on_set      Receives the credentials passed to `set_credentials()`.
	 */
	protected function use_connection( bool|\Throwable $test_result, ?callable $on_set = null ): void {
		remove_all_filters( 'bh_wp_mailboxes_connection_for_account' );
		add_filter(
			'bh_wp_mailboxes_connection_for_account',
			function () use ( $test_result, $on_set ) {
				$connection = Mockery::mock( Email_Connection_Interface::class, Requires_Credentials::class, Supports_Fetching::class );
				$connection->allows( 'set_credentials' )->andReturnUsing(
					function ( $credentials ) use ( $on_set ): void {
						if ( ! is_null( $on_set ) ) {
							$on_set( $credentials );
						}
					}
				);
				if ( $test_result instanceof \Throwable ) {
					$connection->allows( 'test_connection' )->andThrow( $test_result );
				} else {
					$connection->allows( 'test_connection' )->andReturn( $test_result );
				}
				return $connection;
			}
		);
	}

	protected function make_sut(): Email_Account_Manager {
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
			new Email_Controller_Factory(),
			null,
			$this->logger,
		);

		return new Email_Account_Manager( $this->api, $settings, $this->logger );
	}

	/**
	 * Saving a new account creates an IMAP account post and saves the credentials (username defaulting
	 * to the address) to the credentials store, then tests the connection.
	 *
	 * @covers ::save
	 * @covers ::validate
	 */
	public function test_save_creates_account_and_saves_credentials(): void {
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
	 * The validate-certificate choice is saved with the credentials.
	 *
	 * @covers ::save
	 */
	public function test_save_persists_validate_cert(): void {
		$sut = $this->make_sut();

		$result = $sut->save( 'selfsigned@example.com', 'Self-signed', 'imap.internal', '', 'secret', 'TLS', false );

		$this->assertFalse( $this->api->get_account_credentials( $result->account )?->should_validate_cert() );
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
	 * Invalid input throws before anything is saved.
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
	 * @covers ::validate
	 */
	public function test_validation_message_is_not_html_escaped(): void {
		$sut = $this->make_sut();

		try {
			$sut->save( 'inbox@example.com', 'Inbox', '', '', '' );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertStringNotContainsString( '&#039;', $exception->getMessage() );
			$this->assertStringNotContainsString( '&quot;', $exception->getMessage() );
		}
	}

	/**
	 * The upsert is keyed by address, so another connection's account (e.g. the REST ingress) must not
	 * be converted into an IMAP one.
	 *
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
		$this->assertNull( $this->api->get_account_credentials( $account ), 'No credentials should be saved.' );
	}

	/**
	 * A connection test tries the entered details against the server without creating the account or
	 * saving the credentials, and the exact credentials reach the connection.
	 *
	 * @covers ::test_connection
	 * @covers ::validate
	 */
	public function test_test_connection_does_not_save_and_passes_the_credentials(): void {
		$seen = null;
		$this->use_connection(
			new \Exception( 'AUTHENTICATIONFAILED' ),
			function ( $credentials ) use ( &$seen ): void {
				$seen = $credentials;
			}
		);
		$sut = $this->make_sut();

		$result = $sut->test_connection( 'new@example.com', 'New', 'imap.example.com:993', 'user', 'wrong', 'STARTTLS', false );

		$this->assertFalse( $result->success );
		$this->assertSame( 'AUTHENTICATIONFAILED', $result->message );
		$this->assertNull( $this->account_repository->find_by_email_address( 'new@example.com' ), 'Testing must not create the account.' );

		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $seen );
		$this->assertSame( 'imap.example.com:993', $seen->get_email_imap_server() );
		$this->assertSame( 'user', $seen->get_email_account_username() );
		$this->assertSame( 'wrong', $seen->get_email_account_password() );
		$this->assertSame( 'STARTTLS', $seen->get_encryption() );
		$this->assertFalse( $seen->should_validate_cert() );
	}

	/**
	 * When editing, a blank password tests with the saved one, and the saved credentials are left as they were.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_uses_the_saved_password_when_editing_with_a_blank_one(): void {
		$sut   = $this->make_sut();
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'original-password' );

		$seen = null;
		$this->use_connection(
			true,
			function ( $credentials ) use ( &$seen ): void {
				$seen = $credentials;
			}
		);

		$result = $sut->test_connection( 'inbox@example.com', 'Inbox', 'imap.other.com', 'other-user', '', 'TLS' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'imap.other.com', $seen->get_email_imap_server() );
		$this->assertSame( 'original-password', $seen->get_email_account_password() );

		$still_saved = $this->api->get_account_credentials( $saved->account );
		$this->assertSame( 'imap.example.com', $still_saved->get_email_imap_server(), 'Testing must not overwrite the saved credentials.' );
	}

	/**
	 * Editing an account that has no saved credentials cannot fall back to a saved password.
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
	 * @covers ::find_account_by_post_id
	 * @covers ::find_account
	 */
	public function test_find_account(): void {
		$sut   = $this->make_sut();
		$saved = $sut->save( 'inbox@example.com', 'Inbox', 'imap.example.com', '', 'pw' );

		$this->assertSame( $saved->account->get_post_id(), $sut->find_account_by_post_id( $saved->account->get_post_id() )?->get_post_id() );
		$this->assertNull( $sut->find_account_by_post_id( 999999 ) );
		$this->assertSame( 'Inbox', $sut->find_account( 'INBOX@example.com' )?->display_name, 'Addresses match case-insensitively.' );
		$this->assertNull( $sut->find_account( 'nobody@example.com' ) );
	}
}

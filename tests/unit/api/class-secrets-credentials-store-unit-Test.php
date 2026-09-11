<?php
/**
 * Unit tests for the Secrets-API-backed credentials store, with the Secrets API provider mocked.
 *
 * The round trips against the real classes are in the wpunit test; these cover the naming, the
 * dispatch on `type`, and the error paths (unavailable API, WP_Error, malformed records).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Json_Trait;
use BrianHenryIE\WP_Mailboxes\Secrets_API_Loader;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use WP_Error;
use WP_Mock;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Secrets_Credentials_Store
 */
class Secrets_Credentials_Store_Unit_Test extends Unit_Testcase {

	/**
	 * The JSON most recently passed to the provider's set(), keyed by secret name.
	 *
	 * @var array<string, string>
	 */
	protected array $written = array();

	/**
	 * What the mocked provider's get() answers: null, a WP_Error, or a plaintext string wrapped in a secret.
	 *
	 * @var mixed
	 */
	protected mixed $stored = null;

	/**
	 * What the mocked provider's set() answers.
	 *
	 * @var mixed
	 */
	protected mixed $set_result = true;

	/**
	 * What the mocked provider's delete() answers.
	 *
	 * @var mixed
	 */
	protected mixed $delete_result = true;

	/**
	 * The provider injected into the store under test.
	 *
	 * @var ?\WP_Secrets_Provider
	 */
	protected ?\WP_Secrets_Provider $provider = null;

	protected function setup(): void {
		parent::setup();
		$this->written       = array();
		$this->stored        = null;
		$this->set_result    = true;
		$this->delete_result = true;
		$this->provider      = null;
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		WP_Mock::passthruFunction( 'esc_html' );

		// The API's classes (WP_Secret, the provider interface) are not autoloaded.
		$this->assertTrue( Secrets_API_Loader::load() );
	}

	/**
	 * The store under test, with its Secrets API load stubbed (see {@see Stubbed_Secrets_Credentials_Store}).
	 *
	 * @param string                $plugin_slug  The secret namespace.
	 * @param string                $accounts_cpt The accounts post type in the secret key.
	 * @param bool                  $available    What the stubbed API load reports.
	 * @param ?\WP_Secrets_Provider $provider     The provider get_provider() hands out; defaults to the one {@see mock_provider()} built, or a bare mock.
	 */
	protected function make_sut( string $plugin_slug = 'test-plugin', string $accounts_cpt = 'test_accounts', bool $available = true, ?\WP_Secrets_Provider $provider = null ): Stubbed_Secrets_Credentials_Store {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( $plugin_slug );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $accounts_cpt );

		$sut                = new Stubbed_Secrets_Credentials_Store( $settings, $this->logger );
		$sut->stub_provider = $provider ?? $this->provider ?? Mockery::mock( \WP_Secrets_Provider::class );
		$sut->load_result   = $available;

		return $sut;
	}

	/**
	 * A store with the real is_available() check and no injected provider.
	 */
	protected function make_real_sut(): Secrets_Credentials_Store {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_accounts' );

		return new Secrets_Credentials_Store( $settings, $this->logger );
	}

	/**
	 * @param string $email_address The account's address (the only field the store reads).
	 */
	protected function make_account( string $email_address = 'inbox@example.com' ): BH_Email_Account {
		return new BH_Email_Account( 1, 'test_accounts', 'bh_email_ac_active', 'Some\Connection', $email_address, $email_address, null, null, null, null, null, null, null );
	}

	/**
	 * A provider reading its answers from the test's properties at call time: get() answers $stored,
	 * set() records the JSON and answers $set_result, delete() answers $delete_result. Injected into
	 * every store {@see make_sut()} builds afterwards.
	 *
	 * @param mixed $stored        What get() returns: null, a WP_Error, or a plaintext string to wrap in a secret.
	 * @param mixed $set_result    What set() returns (true or WP_Error).
	 * @param mixed $delete_result What delete() returns (true or WP_Error).
	 */
	protected function mock_provider( mixed $stored = null, mixed $set_result = true, mixed $delete_result = true ): void {
		$this->stored        = $stored;
		$this->set_result    = $set_result;
		$this->delete_result = $delete_result;

		if ( ! is_null( $this->provider ) ) {
			return;
		}

		$provider = Mockery::mock( \WP_Secrets_Provider::class );
		$provider->allows( 'get' )->andReturnUsing(
			fn() => is_string( $this->stored ) ? $this->make_secret( $this->stored ) : $this->stored
		);
		$provider->allows( 'set' )->andReturnUsing(
			function ( string $name, string $value ) {
				$this->written[ $name ] = $value;
				return $this->set_result;
			}
		);
		$provider->allows( 'delete' )->andReturnUsing( fn() => $this->delete_result );
		$this->provider = $provider;
	}

	/**
	 * A real WP_Secret (the class is final, so it cannot be mocked) wrapping the plaintext.
	 *
	 * @param string $plaintext What reveal() returns.
	 */
	protected function make_secret( string $plaintext ): \WP_Secret {
		return new \WP_Secret( 'test-plugin/secret', $plaintext, 'fingerprint' );
	}

	/**
	 * With the API loadable (see setup) the store is available without an injected provider.
	 *
	 * @covers ::is_available
	 * @covers ::load_api
	 */
	public function test_is_available_with_the_secrets_api_classes(): void {
		$this->assertTrue( $this->make_real_sut()->is_available() );
	}

	/**
	 * Constructing the store (which the API does on every request) and naming a secret do not load the
	 * Secrets API; only the first read or write does.
	 *
	 * @covers ::__construct
	 * @covers ::get_secret_name
	 * @covers ::is_available
	 */
	public function test_the_api_is_loaded_on_first_use_not_construction(): void {
		$this->mock_provider();
		$sut     = $this->make_sut();
		$account = $this->make_account();

		$sut->get_secret_name( $account );
		$this->assertSame( 0, $sut->load_calls, 'Nothing loaded yet.' );

		$sut->get( $account );
		$this->assertSame( 1, $sut->load_calls, 'Loaded on the first read.' );

		$sut->save( $account, new Imap_Credentials( 's', 'u', 'p' ) );
		$sut->delete( $account );
		$this->assertSame( 3, $sut->load_calls, 'Each operation asks the (idempotent) loader.' );
	}

	/**
	 * An injected provider makes the store available without loading the API.
	 *
	 * @covers ::is_available
	 */
	public function test_an_injected_provider_needs_no_load(): void {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_accounts' );
		$sut              = new Stubbed_Secrets_Credentials_Store( $settings, $this->logger, Mockery::mock( \WP_Secrets_Provider::class ) );
		$sut->load_result = false;

		$this->assertTrue( $sut->is_available() );
		$this->assertSame( 0, $sut->load_calls );
	}

	/**
	 * The name is `{plugin-slug}/{accounts-cpt}-{address}`: every part lowercased and normalised to the
	 * API's allowed characters, the address's `@` written as `-at-`.
	 *
	 * @covers ::get_secret_name
	 * @covers ::normalise_segment
	 */
	public function test_secret_name(): void {
		$sut = $this->make_sut( 'My Plugin! v2', 'my_accounts' );

		$name = $sut->get_secret_name( $this->make_account( 'Inbox@Example.com' ) );

		$this->assertSame( 'my-plugin-v2/my_accounts-inbox-at-example-com', $name );
		$this->assertSame( $name, $sut->get_secret_name( $this->make_account( '  inbox@example.com ' ) ) );
		$this->assertNotSame( $name, $sut->get_secret_name( $this->make_account( 'other@example.com' ) ) );
		$this->assertNotSame( $name, $this->make_sut( 'my-plugin-v2', 'other_accounts' )->get_secret_name( $this->make_account( 'inbox@example.com' ) ) );
	}

	/**
	 * A plugin slug with no usable characters still yields a valid namespace.
	 *
	 * @covers ::normalise_segment
	 */
	public function test_secret_name_falls_back_when_the_slug_is_unusable(): void {
		$this->assertStringStartsWith( 'bh-wp-mailboxes/', $this->make_sut( '---', 'accounts' )->get_secret_name( $this->make_account() ) );
	}

	/**
	 * @covers ::get
	 */
	public function test_get_without_the_secrets_api_logs_and_returns_null(): void {
		$this->assertNull( $this->make_sut( available: false )->get( $this->make_account() ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Secrets API is not available' ) );
	}

	/**
	 * @covers ::get
	 */
	public function test_get_returns_null_when_nothing_is_saved(): void {
		$this->mock_provider( null );

		$this->assertNull( $this->make_sut()->get( $this->make_account() ) );
		$this->assertFalse( $this->logger->hasErrorRecords() );
	}

	/**
	 * A secret that exists but cannot be read (broken keyring) is logged and treated as absent.
	 *
	 * @covers ::get
	 */
	public function test_get_logs_and_returns_null_on_wp_error(): void {
		$this->mock_provider( new WP_Error( 'secret_key_unavailable', 'Keyring broken.' ) );

		$this->assertNull( $this->make_sut()->get( $this->make_account() ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Keyring broken.' ) );
	}

	/**
	 * @covers ::get
	 */
	public function test_get_logs_and_returns_null_for_invalid_json(): void {
		$this->mock_provider( 'not json' );

		$this->assertNull( $this->make_sut()->get( $this->make_account() ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'not valid JSON' ) );
	}

	/**
	 * @covers ::get
	 * @covers ::from_array
	 */
	public function test_get_logs_and_returns_null_for_an_unknown_type(): void {
		$this->mock_provider( '{"type":"pop3"}' );

		$this->assertNull( $this->make_sut()->get( $this->make_account() ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Unknown credentials type: pop3' ) );
	}

	/**
	 * @covers ::get
	 * @covers ::from_array
	 */
	public function test_get_logs_and_returns_null_for_gmail_without_a_client(): void {
		$this->mock_provider( '{"type":"gmail","access_token":null}' );

		$this->assertNull( $this->make_sut()->get( $this->make_account() ) );
		$this->assertTrue( $this->logger->hasErrorThatContains( 'missing the OAuth client' ) );
	}

	/**
	 * IMAP credentials are written as a typed JSON document and read back as Imap_Credentials; an empty
	 * encryption string ("none") survives, and only a record with no encryption key at all defaults to TLS.
	 *
	 * @covers ::save
	 * @covers ::get
	 * @covers ::from_array
	 */
	public function test_imap_round_trip(): void {
		$this->mock_provider();
		$sut     = $this->make_sut();
		$account = $this->make_account();

		$sut->save( $account, new Imap_Credentials( 'imap.example.com:993', 'user', 'p<a&ss"word', '' ) );

		$name = $sut->get_secret_name( $account );
		$this->assertArrayHasKey( $name, $this->written );
		$this->assertSame(
			array(
				'type'          => 'imap',
				'server'        => 'imap.example.com:993',
				'username'      => 'user',
				'password'      => 'p<a&ss"word',
				'encryption'    => '',
				'validate_cert' => true,
			),
			json_decode( $this->written[ $name ], true )
		);
		$this->assertTrue( $this->logger->hasInfoThatContains( 'Saved imap credentials for inbox@example.com' ) );

		// Read the written document back.
		$this->mock_provider( $this->written[ $name ] );
		$loaded = $this->make_sut()->get( $account );
		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $loaded );
		$this->assertSame( 'imap.example.com:993', $loaded->get_email_imap_server() );
		$this->assertSame( 'user', $loaded->get_email_account_username() );
		$this->assertSame( 'p<a&ss"word', $loaded->get_email_account_password() );
		$this->assertSame( '', $loaded->get_encryption() );

		$this->mock_provider( '{"type":"imap","server":"s","username":"u","password":"p"}' );
		$this->assertSame( 'TLS', $this->make_sut()->get( $account )->get_encryption() );
	}

	/**
	 * Whatever the implementation, its own jsonSerialize() is what is written (e.g. an env-backed one
	 * using the trait captures its values).
	 *
	 * @covers ::save
	 */
	public function test_save_writes_the_credentials_own_json(): void {
		$this->mock_provider();
		$credentials = new class() implements IMAP_Credentials_Interface {
			use IMAP_Credentials_Json_Trait;

			public function get_email_imap_server(): string {
				return 'env.example.com';
			}
			public function get_email_account_username(): string {
				return 'env-user';
			}
			public function get_email_account_password(): string {
				return 'env-pass';
			}
			public function get_encryption(): string {
				return 'STARTTLS';
			}
			public function should_validate_cert(): bool {
				return false;
			}
		};
		$sut         = $this->make_sut();
		$account     = $this->make_account();

		$sut->save( $account, $credentials );

		$this->assertSame(
			array(
				'type'          => 'imap',
				'server'        => 'env.example.com',
				'username'      => 'env-user',
				'password'      => 'env-pass',
				'encryption'    => 'STARTTLS',
				'validate_cert' => false,
			),
			json_decode( $this->written[ $sut->get_secret_name( $account ) ], true )
		);
	}

	/**
	 * @covers ::save
	 * @covers ::get
	 * @covers ::from_array
	 */
	public function test_gmail_round_trip_with_and_without_a_token(): void {
		$this->mock_provider();
		$sut     = $this->make_sut();
		$account = $this->make_account( 'you@gmail.com' );
		$client  = new OAuth_Client_Credentials( 'id', 'project', 'https://auth', 'https://token', 'https://certs', 'secret', array( 'http://localhost' ), array( 'https://example.com' ) );
		$token   = new Access_Token( 'ya29.x', 3599, 'scope', 'Bearer', 1700000000, 'refresh' );
		$name    = $sut->get_secret_name( $account );

		$sut->save( $account, new Gmail_Credentials( $client, null ) );
		$written = json_decode( $this->written[ $name ], true );
		$this->assertSame( 'gmail', $written['type'] );
		$this->assertSame( 'secret', $written['client']['client_secret'] );
		$this->assertNull( $written['access_token'] );

		$this->mock_provider( $this->written[ $name ] );
		$loaded = $this->make_sut()->get( $account );
		$this->assertInstanceOf( Google_API_Credentials_Interface::class, $loaded );
		$this->assertEquals( $client, $loaded->get_project_credentials() );
		$this->assertNull( $loaded->get_access_token() );

		$sut->save( $account, new Gmail_Credentials( $client, $token ) );
		$this->assertSame( 'refresh', json_decode( $this->written[ $name ], true )['access_token']['refresh_token'] );

		$this->mock_provider( $this->written[ $name ] );
		$loaded = $this->make_sut()->get( $account );
		$this->assertEquals( $token, $loaded->get_access_token() );
	}

	/**
	 * Non-string entries in the client's URI lists are dropped rather than breaking the value object.
	 *
	 * @covers ::from_array
	 */
	public function test_gmail_client_uri_lists_keep_only_strings(): void {
		$this->mock_provider( '{"type":"gmail","client":{"client_id":"id","project_id":"p","auth_uri":"a","token_uri":"t","auth_provider_x509_cert_url":"c","client_secret":"s","redirect_uris":["http://localhost",5,null],"javascript_origins":"not-a-list"}}' );

		$loaded = $this->make_sut()->get( $this->make_account() );

		$this->assertInstanceOf( Google_API_Credentials_Interface::class, $loaded );
		$this->assertSame( array( 'http://localhost' ), $loaded->get_project_credentials()->redirect_uris );
		$this->assertSame( array(), $loaded->get_project_credentials()->javascript_origins );
	}

	/**
	 * Reads ask the provider for the current version, site scope.
	 *
	 * @covers ::get
	 */
	public function test_get_asks_the_provider_for_the_current_version(): void {
		$account  = $this->make_account();
		$provider = Mockery::mock( \WP_Secrets_Provider::class );
		$sut      = $this->make_sut( provider: $provider );
		$provider->expects( 'get' )
			->with( $sut->get_secret_name( $account ), 'current' )
			->once()
			->andReturn( $this->make_secret( '{"type":"imap","server":"s","username":"u","password":"p","encryption":"TLS"}' ) );

		$loaded = $sut->get( $account );

		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $loaded );
		$this->assertSame( 'p', $loaded->get_email_account_password() );
	}

	/**
	 * @covers ::save
	 * @covers ::delete
	 */
	public function test_save_and_delete_go_through_the_provider(): void {
		$account  = $this->make_account();
		$provider = Mockery::mock( \WP_Secrets_Provider::class );
		$sut      = $this->make_sut( provider: $provider );
		$name     = $sut->get_secret_name( $account );
		$provider->expects( 'set' )
			->withArgs( fn( string $set_name, string $value ): bool => $set_name === $name && 'imap' === json_decode( $value, true )['type'] )
			->once()
			->andReturn( true );
		$provider->expects( 'delete' )->with( $name )->once()->andReturn( true );

		$sut->save( $account, new Imap_Credentials( 's', 'u', 'p' ) );
		$sut->delete( $account );
	}

	/**
	 * A provider error surfaces the same way a function error does.
	 *
	 * @covers ::save
	 */
	public function test_provider_wp_error_on_save_throws(): void {
		$provider = Mockery::mock( \WP_Secrets_Provider::class );
		$provider->allows( 'set' )->andReturn( new WP_Error( 'secret_key_unavailable', 'No site key.' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'No site key.' );
		$this->make_sut( provider: $provider )->save( $this->make_account(), new Imap_Credentials( 's', 'u', 'p' ) );
	}

	/**
	 * The default provider is libsodium encryption over the options store, keyed from wp-config.
	 *
	 * @covers ::get_provider
	 */
	public function test_default_provider_is_libsodium_over_the_options_store(): void {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_accounts' );
		$sut = new Stubbed_Secrets_Credentials_Store( $settings, $this->logger );

		$this->assertInstanceOf( \WP_Secrets_Libsodium_Provider::class, $sut->provider() );
		$this->assertSame( $sut->provider(), $sut->provider(), 'Built once.' );
	}

	/**
	 * Credentials whose `type` the store cannot rebuild are refused before the Secrets API is touched.
	 *
	 * @covers ::save
	 */
	public function test_save_refuses_unknown_credentials_types(): void {
		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$credentials->allows( 'jsonSerialize' )->andReturn( array( 'type' => 'pop3' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'serialised as pop3' );

		$this->make_sut()->save( $this->make_account(), $credentials );
	}

	/**
	 * @covers ::save
	 */
	public function test_save_refuses_credentials_without_a_type(): void {
		$credentials = Mockery::mock( Account_Credentials_Interface::class );
		$credentials->allows( 'jsonSerialize' )->andReturn( array( 'server' => 's' ) );

		$this->expectException( InvalidArgumentException::class );

		$this->make_sut()->save( $this->make_account(), $credentials );
	}

	/**
	 * @covers ::save
	 */
	public function test_save_without_the_secrets_api_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not available' );

		$this->make_sut( available: false )->save( $this->make_account(), new Imap_Credentials( 's', 'u', 'p' ) );
	}

	/**
	 * @covers ::save
	 */
	public function test_save_throws_and_logs_on_wp_error(): void {
		$this->mock_provider( null, new WP_Error( 'secret_key_unavailable', 'WP_SECRETS_KEY is not defined.' ) );

		try {
			$this->make_sut()->save( $this->make_account(), new Imap_Credentials( 's', 'u', 'p' ) );
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'WP_SECRETS_KEY is not defined.', $exception->getMessage() );
		}
		$this->assertTrue( $this->logger->hasErrorThatContains( 'Failed to save credentials for inbox@example.com' ) );
	}

	/**
	 * @covers ::delete
	 */
	public function test_delete_without_the_secrets_api_throws(): void {
		$this->expectException( RuntimeException::class );

		$this->make_sut( available: false )->delete( $this->make_account() );
	}

	/**
	 * @covers ::delete
	 */
	public function test_delete_succeeds_quietly(): void {
		$this->mock_provider();

		$this->make_sut()->delete( $this->make_account() );

		$this->assertFalse( $this->logger->hasErrorRecords() );
	}

	/**
	 * @covers ::delete
	 */
	public function test_delete_throws_and_logs_on_wp_error(): void {
		$this->mock_provider( null, true, new WP_Error( 'secret_store_unavailable', 'Store down.' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Store down.' );

		$this->make_sut()->delete( $this->make_account() );
	}
}

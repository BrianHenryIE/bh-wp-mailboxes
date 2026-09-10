<?php
/**
 * WPUnit tests for the Secrets-API-backed credentials store, against the real feature plugin loaded from vendor.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Secrets_API_Loader;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use InvalidArgumentException;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Secrets_Credentials_Store
 */
class Secrets_Credentials_Store_WPUnit_Test extends WPUnit_Testcase {

	public function setUp(): void {
		parent::setUp();
		$this->assertTrue( Secrets_API_Loader::load(), 'The Secrets API feature plugin should load from vendor.' );
	}

	protected function make_sut( string $plugin_slug = 'test-plugin', string $accounts_cpt = 'test_accounts' ): Secrets_Credentials_Store {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( $plugin_slug );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $accounts_cpt );

		return new Secrets_Credentials_Store( $settings, $this->logger );
	}

	protected function make_account( string $email_address, string $connection_type_class = ImapEngine_Imap_Email_Connection::class ): BH_Email_Account {
		return new BH_Email_Account( 1, 'test_accounts', 'bh_email_ac_active', $connection_type_class, $email_address, $email_address, null, null, null, null, null, null, null );
	}

	/**
	 * @covers ::is_available
	 */
	public function test_is_available_with_the_feature_plugin_loaded(): void {
		$this->assertTrue( $this->make_sut()->is_available() );
	}

	/**
	 * The secret name is namespaced by plugin slug and keyed by post type + hashed address, and passes the API's validation.
	 *
	 * @covers ::get_secret_name
	 */
	public function test_secret_name_is_valid_and_hides_the_address(): void {
		$sut  = $this->make_sut( 'My Plugin!', 'my_accounts' );
		$name = $sut->get_secret_name( $this->make_account( 'Inbox@Example.com' ) );

		$this->assertTrue( wp_secrets_validate_name( $name ) );
		$this->assertStringStartsWith( 'my-plugin/my_accounts-', $name );
		$this->assertStringNotContainsString( 'example', $name );
		$this->assertSame( $name, $sut->get_secret_name( $this->make_account( 'inbox@example.com' ) ), 'Addresses are matched case-insensitively.' );
	}

	/**
	 * @covers ::save
	 * @covers ::get
	 * @covers ::delete
	 */
	public function test_imap_credentials_round_trip_and_delete(): void {
		$sut     = $this->make_sut();
		$account = $this->make_account( 'inbox@example.com' );

		$this->assertNull( $sut->get( $account ) );

		$sut->save( $account, new Imap_Credentials( 'imap.example.com:993', 'user', 'p<a&ss"word', '' ) );

		$loaded = $sut->get( $account );
		$this->assertInstanceOf( IMAP_Credentials_Interface::class, $loaded );
		$this->assertSame( 'imap.example.com:993', $loaded->get_email_imap_server() );
		$this->assertSame( 'user', $loaded->get_email_account_username() );
		$this->assertSame( 'p<a&ss"word', $loaded->get_email_account_password() );
		$this->assertSame( '', $loaded->get_encryption(), 'An empty encryption (none) must not become the default.' );

		// The value is encrypted at rest: no option holds the password in plaintext.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Asserting on the raw stored rows is the point.
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%p<a&ss"word%' ) ) );

		$sut->delete( $account );
		$this->assertNull( $sut->get( $account ) );

		// Deleting again is not an error.
		$sut->delete( $account );
	}

	/**
	 * @covers ::save
	 * @covers ::get
	 */
	public function test_gmail_credentials_round_trip_with_and_without_a_token(): void {
		$sut     = $this->make_sut();
		$account = $this->make_account( 'you@gmail.com', Google_API_Credentials_Interface::class );
		$client  = new OAuth_Client_Credentials( 'id', 'project', 'https://auth', 'https://token', 'https://certs', 'secret', array( 'http://localhost' ), array() );
		$token   = new Access_Token( 'ya29.x', 3599, 'scope', 'Bearer', 1700000000, 'refresh' );

		$sut->save( $account, new Gmail_Credentials( $client, null ) );
		$loaded = $sut->get( $account );
		$this->assertInstanceOf( Google_API_Credentials_Interface::class, $loaded );
		$this->assertEquals( $client, $loaded->get_project_credentials() );
		$this->assertNull( $loaded->get_access_token() );

		$sut->save( $account, new Gmail_Credentials( $client, $token ) );
		$loaded = $sut->get( $account );
		$this->assertInstanceOf( Google_API_Credentials_Interface::class, $loaded );
		$this->assertEquals( $token, $loaded->get_access_token() );
	}

	/**
	 * Two library instances (plugin slug / accounts post type) keep separate credentials for the same address.
	 *
	 * @covers ::get_secret_name
	 */
	public function test_instances_do_not_share_credentials(): void {
		$account = $this->make_account( 'inbox@example.com' );
		$one     = $this->make_sut( 'plugin-one', 'one_accounts' );
		$two     = $this->make_sut( 'plugin-two', 'two_accounts' );

		$one->save( $account, new Imap_Credentials( 'one.example.com', 'u', 'p' ) );

		$this->assertNull( $two->get( $account ) );
		$this->assertNotNull( $one->get( $account ) );
	}

	/**
	 * @covers ::save
	 */
	public function test_unknown_credentials_type_is_refused(): void {
		$sut         = $this->make_sut();
		$credentials = Mockery::mock( \BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface::class );
		$credentials->allows( 'jsonSerialize' )->andReturn( array( 'type' => 'pop3' ) );

		$this->expectException( InvalidArgumentException::class );
		$sut->save( $this->make_account( 'inbox@example.com' ), $credentials );
	}
}

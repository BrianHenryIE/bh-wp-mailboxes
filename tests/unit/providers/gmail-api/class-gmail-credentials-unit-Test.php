<?php
/**
 * Unit tests for the Gmail credentials value object.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain json_encode() and temp files are fine in tests.

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Credentials
 */
class Gmail_Credentials_Unit_Test extends Unit_Testcase {

	private function make_project_credentials(): OAuth_Client_Credentials {
		return new OAuth_Client_Credentials( 'id', 'project', 'https://auth', 'https://token', 'https://certs', 'secret' );
	}

	/**
	 * @covers ::__construct
	 * @covers ::get_project_credentials
	 * @covers ::get_access_token
	 */
	public function test_returns_the_client_and_token_it_was_built_with(): void {
		$client = $this->make_project_credentials();
		$token  = new Access_Token( 'ya29.x', 3599, 'scope', 'Bearer', 1700000000, 'refresh' );

		$sut = new Gmail_Credentials( $client, $token );

		$this->assertSame( $client, $sut->get_project_credentials() );
		$this->assertSame( $token, $sut->get_access_token() );
	}

	/**
	 * Before the account is authorised there is a client but no token.
	 *
	 * @covers ::get_access_token
	 */
	public function test_access_token_defaults_to_null(): void {
		$sut = new Gmail_Credentials( $this->make_project_credentials() );

		$this->assertNull( $sut->get_access_token() );
	}

	/**
	 * The stored representation is the values plus `type`, and from_array() rebuilds an equal object.
	 *
	 * @covers ::jsonSerialize
	 * @covers ::from_array
	 */
	public function test_json_round_trip(): void {
		$client = new OAuth_Client_Credentials( 'id', 'project', 'https://auth', 'https://token', 'https://certs', 'secret', array( 'http://localhost' ), array( 'https://example.com' ) );
		$token  = new Access_Token( 'ya29.x', 3599, 'scope', 'Bearer', 1700000000, 'refresh' );
		$sut    = new Gmail_Credentials( $client, $token );

		$json = $sut->jsonSerialize();
		$this->assertSame( 'gmail', $json['type'] );
		$this->assertSame( 'secret', $json['client']['client_secret'] );
		$this->assertSame( 'refresh', $json['access_token']['refresh_token'] );

		$rebuilt = Gmail_Credentials::from_array( json_decode( json_encode( $sut ), true ) );
		$this->assertEquals( $sut, $rebuilt );

		$without_token = Gmail_Credentials::from_array( json_decode( json_encode( new Gmail_Credentials( $client ) ), true ) );
		$this->assertNull( $without_token->get_access_token() );
		$this->assertEquals( $client, $without_token->get_project_credentials() );
	}

	/**
	 * @covers ::from_array
	 */
	public function test_from_array_rejects_other_types_and_a_missing_client(): void {
		try {
			Gmail_Credentials::from_array( array( 'type' => 'imap' ) );
			$this->fail( 'Expected an exception for the wrong type.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'Not Gmail', $exception->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		Gmail_Credentials::from_array(
			array(
				'type'         => 'gmail',
				'access_token' => null,
			)
		);
	}

	/**
	 * A throwaway directory for the from_files() tests.
	 */
	private function make_dir(): string {
		$dir = sys_get_temp_dir() . '/bh-wp-mailboxes-creds-' . uniqid();
		mkdir( $dir );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * Directories created by the current test.
	 *
	 * @var string[]
	 */
	private array $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
			rmdir( $dir );
		}
		$this->dirs = array();
		parent::tearDown();
	}

	private const CLIENT_SECRET_JSON = '{"installed":{"client_id":"id","project_id":"project","auth_uri":"https://auth","token_uri":"https://token","auth_provider_x509_cert_url":"https://certs","client_secret":"secret","redirect_uris":["http://localhost"]}}';
	private const ACCESS_TOKEN_JSON  = '{"access_token":"ya29.token","expires_in":3599,"scope":"https://www.googleapis.com/auth/gmail.readonly","token_type":"Bearer","created":1700000000,"refresh_token":"1//refresh"}';

	/**
	 * @covers ::from_files
	 */
	public function test_from_files_reads_the_client_and_token(): void {
		$dir = $this->make_dir();
		file_put_contents( $dir . '/client_secret.json', self::CLIENT_SECRET_JSON );
		file_put_contents( $dir . '/access_token.json', self::ACCESS_TOKEN_JSON );

		$sut = Gmail_Credentials::from_files( $dir );

		$this->assertSame( 'secret', $sut->get_project_credentials()->client_secret );
		$this->assertSame( array( 'http://localhost' ), $sut->get_project_credentials()->redirect_uris );
		$token = $sut->get_access_token();
		$this->assertNotNull( $token );
		$this->assertSame( 'ya29.token', $token->access_token );
		$this->assertSame( '1//refresh', $token->refresh_token );
		$this->assertSame( 1700000000, $token->created );
	}

	/**
	 * Before authorisation there is no token file; the client alone is enough to start the OAuth flow.
	 *
	 * @covers ::from_files
	 */
	public function test_from_files_token_is_null_when_the_file_is_absent(): void {
		$dir = $this->make_dir();
		file_put_contents( $dir . '/client_secret.json', self::CLIENT_SECRET_JSON );

		$sut = Gmail_Credentials::from_files( $dir );

		$this->assertNull( $sut->get_access_token() );
		$this->assertNull( $sut->jsonSerialize()['access_token'] );
	}

	/**
	 * The file names are configurable, and the object captures the values, not the paths.
	 *
	 * @covers ::from_files
	 */
	public function test_from_files_with_custom_names_captures_values_not_paths(): void {
		$dir = $this->make_dir();
		file_put_contents( $dir . '/google_desktop_client_secret.json', self::CLIENT_SECRET_JSON );
		file_put_contents( $dir . '/token.json', self::ACCESS_TOKEN_JSON );

		$json = Gmail_Credentials::from_files( $dir, 'google_desktop_client_secret.json', 'token.json' )->jsonSerialize();

		$this->assertSame( 'gmail', $json['type'] );
		$this->assertSame( 'secret', $json['client']['client_secret'] );
		$this->assertSame( '1//refresh', $json['access_token']['refresh_token'] );
		$this->assertStringNotContainsString( $dir, json_encode( $json ) );
	}

	/**
	 * @covers ::from_files
	 */
	public function test_from_files_throws_when_the_client_file_is_missing(): void {
		\WP_Mock::passthruFunction( 'esc_html' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'OAuth client file not found' );

		Gmail_Credentials::from_files( $this->make_dir() );
	}

	/**
	 * It is what the Gmail connection and the credentials store expect.
	 *
	 * @coversNothing
	 */
	public function test_implements_the_credentials_interfaces(): void {
		$sut = new Gmail_Credentials( $this->make_project_credentials() );

		$this->assertInstanceOf( Google_API_Credentials_Interface::class, $sut );
		$this->assertInstanceOf( Account_Credentials_Interface::class, $sut );
	}
}

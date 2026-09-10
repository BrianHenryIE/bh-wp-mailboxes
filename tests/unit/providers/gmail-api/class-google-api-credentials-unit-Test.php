<?php
/**
 * Unit tests for Google_API_Credentials file loading.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain json_encode() and temp files are fine in tests.

use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials
 */
class Google_API_Credentials_Unit_Test extends Unit_Testcase {

	/**
	 * A unique temporary directory for credential files.
	 *
	 * @var string
	 */
	private string $dir;

	#[\Override]
	protected function setup(): void {
		parent::setup();
		$this->dir = sys_get_temp_dir() . '/bh-wp-mailboxes-creds-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->dir );
	}

	#[\Override]
	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: array() );
		if ( is_dir( $this->dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	/**
	 * Before the first authorization there is no access_token.json — get_access_token() returns null
	 * rather than throwing.
	 *
	 * @covers ::get_access_token
	 */
	public function test_get_access_token_returns_null_when_file_absent(): void {
		$this->assertNull( new Google_API_Credentials( $this->dir )->get_access_token() );
	}

	/**
	 * When access_token.json is present it is parsed into an Access_Token.
	 *
	 * @covers ::get_access_token
	 */
	public function test_get_access_token_reads_file(): void {

		$json = <<<'JSON'
		{
			"access_token": "ya29.token",
			"expires_in": 3599,
			"scope": "https://www.googleapis.com/auth/gmail.readonly",
			"token_type": "Bearer",
			"created": 1700000000,
			"refresh_token": "1//refresh"
		}
		JSON;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dir . '/access_token.json', $json );

		$token = new Google_API_Credentials( $this->dir )->get_access_token();

		$this->assertInstanceOf( Access_Token::class, $token );
		$this->assertSame( 'ya29.token', $token->access_token );
		$this->assertSame( '1//refresh', $token->refresh_token );
	}

	/**
	 * The stored representation captures the files' contents (client and token), not the paths.
	 *
	 * @covers ::jsonSerialize
	 */
	public function test_json_serialize_captures_file_contents(): void {
		file_put_contents(
			$this->dir . '/client_secret.json',
			'{"installed":{"client_id":"id","project_id":"project","auth_uri":"https://auth","token_uri":"https://token","auth_provider_x509_cert_url":"https://certs","client_secret":"secret","redirect_uris":["http://localhost"]}}'
		);
		file_put_contents(
			$this->dir . '/access_token.json',
			'{"access_token":"ya29.token","expires_in":3599,"scope":"scope","token_type":"Bearer","created":1700000000,"refresh_token":"refresh"}'
		);

		$json = ( new Google_API_Credentials( $this->dir ) )->jsonSerialize();

		$this->assertSame( 'gmail', $json['type'] );
		$this->assertSame( 'secret', $json['client']['client_secret'] );
		$this->assertSame( array( 'http://localhost' ), $json['client']['redirect_uris'] );
		$this->assertSame( 'refresh', $json['access_token']['refresh_token'] );
		$this->assertStringNotContainsString( $this->dir, json_encode( $json ) );

		unlink( $this->dir . '/access_token.json' );
		$this->assertNull( ( new Google_API_Credentials( $this->dir ) )->jsonSerialize()['access_token'] );
	}
}

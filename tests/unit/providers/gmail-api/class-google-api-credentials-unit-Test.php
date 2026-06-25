<?php
/**
 * Unit tests for Google_API_Credentials file loading.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

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
}

<?php
/**
 * Unit tests for the wp_options-backed pasted Gmail credentials.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use RuntimeException;
use WP_Mock;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Gmail_Credentials_Options
 */
class Gmail_Credentials_Options_Unit_Test extends Unit_Testcase {

	private const CLIENT_SECRET_JSON = '{"installed":{"client_id":"id-123","project_id":"project-123","auth_uri":"https://accounts.google.com/o/oauth2/auth","token_uri":"https://oauth2.googleapis.com/token","auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs","client_secret":"secret-123"}}';

	private const ACCESS_TOKEN_JSON = '{"access_token":"token-123","expires_in":3599,"scope":"https://www.googleapis.com/auth/gmail.readonly","token_type":"Bearer","created":1755000000,"refresh_token":"refresh-123"}';

	/**
	 * Stub the three backing options.
	 *
	 * @param string $email_address The stored email address option value.
	 * @param string $client_secret The stored client secret JSON option value.
	 * @param string $access_token  The stored access token JSON option value.
	 */
	private function stub_options( string $email_address, string $client_secret, string $access_token ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( Gmail_Credentials_Options::OPTION_EMAIL_ADDRESS, '' )
			->andReturn( $email_address );
		WP_Mock::userFunction( 'get_option' )
			->with( Gmail_Credentials_Options::OPTION_CLIENT_SECRET_JSON, '' )
			->andReturn( $client_secret );
		WP_Mock::userFunction( 'get_option' )
			->with( Gmail_Credentials_Options::OPTION_ACCESS_TOKEN_JSON, '' )
			->andReturn( $access_token );
	}

	/**
	 * Saving persists all three values to their wp_options.
	 *
	 * @covers ::save
	 */
	public function test_save_updates_the_three_options(): void {

		WP_Mock::userFunction( 'update_option' )
			->with( Gmail_Credentials_Options::OPTION_EMAIL_ADDRESS, 'test@gmail.com' )
			->once();
		WP_Mock::userFunction( 'update_option' )
			->with( Gmail_Credentials_Options::OPTION_CLIENT_SECRET_JSON, self::CLIENT_SECRET_JSON )
			->once();
		WP_Mock::userFunction( 'update_option' )
			->with( Gmail_Credentials_Options::OPTION_ACCESS_TOKEN_JSON, self::ACCESS_TOKEN_JSON )
			->once();

		Gmail_Credentials_Options::save( 'test@gmail.com', self::CLIENT_SECRET_JSON, self::ACCESS_TOKEN_JSON );
	}

	/**
	 * The stored client secret JSON parses into OAuth client credentials.
	 *
	 * @covers ::get_project_credentials
	 * @covers ::get_email_address
	 * @covers ::is_complete
	 */
	public function test_valid_stored_credentials_are_parsed(): void {

		$this->stub_options( 'test@gmail.com', self::CLIENT_SECRET_JSON, self::ACCESS_TOKEN_JSON );

		$sut = new Gmail_Credentials_Options();

		$this->assertSame( 'test@gmail.com', $sut->get_email_address() );
		$this->assertTrue( $sut->is_complete() );

		$project_credentials = $sut->get_project_credentials();
		$this->assertSame( 'id-123', $project_credentials->client_id );
		$this->assertSame( 'secret-123', $project_credentials->client_secret );
	}

	/**
	 * The stored access token JSON parses into an access token; an empty option returns null.
	 *
	 * @covers ::get_access_token
	 */
	public function test_get_access_token(): void {

		$this->stub_options( 'test@gmail.com', self::CLIENT_SECRET_JSON, self::ACCESS_TOKEN_JSON );

		$access_token = new Gmail_Credentials_Options()->get_access_token();

		$this->assertNotNull( $access_token );
		$this->assertSame( 'token-123', $access_token->access_token );
		$this->assertSame( 'refresh-123', $access_token->refresh_token );
	}

	/**
	 * An unset access token option means "not authorized yet", not an error.
	 *
	 * @covers ::get_access_token
	 */
	public function test_get_access_token_returns_null_when_option_empty(): void {

		$this->stub_options( 'test@gmail.com', self::CLIENT_SECRET_JSON, '' );

		$this->assertNull( new Gmail_Credentials_Options()->get_access_token() );
	}

	/**
	 * Incomplete or unparseable stored values are reported as not complete.
	 *
	 * @covers ::is_complete
	 * @covers ::decode
	 */
	public function test_is_complete_false_when_email_missing_or_json_invalid(): void {

		$this->stub_options( '', self::CLIENT_SECRET_JSON, '' );
		$this->assertFalse( new Gmail_Credentials_Options()->is_complete() );
	}

	/**
	 * A stored client secret that is not valid JSON is not complete, and throws when parsed directly.
	 *
	 * @covers ::is_complete
	 * @covers ::get_project_credentials
	 * @covers ::decode
	 */
	public function test_invalid_client_secret_json(): void {

		$this->stub_options( 'test@gmail.com', 'not-json', '' );

		WP_Mock::passthruFunction( 'esc_html' );

		$sut = new Gmail_Credentials_Options();

		$this->assertFalse( $sut->is_complete() );

		$this->expectException( RuntimeException::class );
		$sut->get_project_credentials();
	}
}

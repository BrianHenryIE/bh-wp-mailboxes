<?php
/**
 * Unit tests for the Gmail connection's no-save access-token refresh.
 *
 * The Google client is mocked (via the `make_client()` seam) so the refresh-token exchange can be
 * exercised without live Google credentials.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Google_Client;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Email_Connection
 */
class Gmail_Refresh_Token_Unit_Test extends Unit_Testcase {

	/**
	 * A minimal project credentials value object.
	 */
	private function make_project_credentials(): OAuth_Client_Credentials {
		return new OAuth_Client_Credentials(
			client_id: 'client-id.apps.googleusercontent.com',
			project_id: 'my-project',
			auth_uri: 'https://accounts.google.com/o/oauth2/auth',
			token_uri: 'https://oauth2.googleapis.com/token',
			auth_provider_x509_cert_url: 'https://www.googleapis.com/oauth2/v1/certs',
			client_secret: 'client-secret',
			redirect_uris: array( 'https://example.com/oauth2callback' ),
			javascript_origins: array( 'https://example.com' ),
		);
	}

	/**
	 * An existing (stale) access token carrying the refresh token.
	 */
	private function make_existing_token(): Access_Token {
		return new Access_Token(
			access_token: 'stale-access-token',
			expires_in: 3599,
			scope: 'https://www.googleapis.com/auth/gmail.readonly',
			token_type: 'Bearer',
			created: 1700000000,
			refresh_token: 'the-refresh-token',
		);
	}

	/**
	 * A mock Google client with the OAuth-configuration setters allowed (no-ops).
	 *
	 * @return Google_Client&\Mockery\MockInterface
	 */
	private function make_oauth_client_mock(): Google_Client {
		$client = Mockery::mock( Google_Client::class );
		$client->allows( 'setLogger' );
		$client->allows( 'setScopes' );
		$client->allows( 'setAuthConfig' );
		$client->allows( 'setAccessType' );
		$client->allows( 'setPrompt' );
		return $client;
	}

	/**
	 * Build the connection with `make_client()` overridden to return the given mock client, and its
	 * credentials set to the given (mocked) credentials.
	 *
	 * @param Google_Client                    $client      The mock Google client.
	 * @param Google_API_Credentials_Interface $credentials The mock credentials.
	 */
	private function make_sut( Google_Client $client, Google_API_Credentials_Interface $credentials ): Gmail_Email_Connection {

		$settings = Mockery::mock( Email_Account_Settings_Interface::class );

		$sut = Mockery::mock( Gmail_Email_Connection::class, array( $settings, $this->logger ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$sut->allows( 'make_client' )->andReturn( $client );

		$sut->set_credentials( $credentials );

		return $sut;
	}

	/**
	 * The refresh response is mapped to a new Access_Token; the original refresh token is carried over
	 * (Google omits it from the response).
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_refresh_returns_new_access_token(): void {

		$client = $this->make_oauth_client_mock();
		$client->expects( 'fetchAccessTokenWithRefreshToken' )
			->with( 'the-refresh-token' )
			->once()
			->andReturn(
				array(
					'access_token' => 'fresh-access-token',
					'expires_in'   => 3599,
					'scope'        => 'https://www.googleapis.com/auth/gmail.readonly',
					'token_type'   => 'Bearer',
					'created'      => 1700003600,
				)
			);

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );
		$credentials->allows( 'get_project_credentials' )->andReturn( $this->make_project_credentials() );
		$credentials->allows( 'get_access_token' )->andReturn( $this->make_existing_token() );

		$result = $this->make_sut( $client, $credentials )->refresh_access_token();

		$this->assertInstanceOf( Access_Token::class, $result );
		$this->assertSame( 'fresh-access-token', $result->access_token );
		$this->assertSame( 'the-refresh-token', $result->refresh_token );
		$this->assertSame( 1700003600, $result->created );
	}

	/**
	 * A token endpoint error is surfaced as an exception.
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_refresh_throws_on_error_response(): void {

		$client = $this->make_oauth_client_mock();
		$client->allows( 'fetchAccessTokenWithRefreshToken' )
			->andReturn( array( 'error' => 'invalid_grant' ) );

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );
		$credentials->allows( 'get_project_credentials' )->andReturn( $this->make_project_credentials() );
		$credentials->allows( 'get_access_token' )->andReturn( $this->make_existing_token() );

		\WP_Mock::passthruFunction( 'esc_html' );

		$this->expectException( \Exception::class );

		$this->make_sut( $client, $credentials )->refresh_access_token();
	}

	/**
	 * Refreshing without a stored refresh token is an error.
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_refresh_throws_when_no_refresh_token(): void {

		$client = Mockery::mock( Google_Client::class );

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );
		$credentials->allows( 'get_project_credentials' )->andReturn( $this->make_project_credentials() );
		$credentials->allows( 'get_access_token' )->andReturn( null );

		$this->expectException( \Exception::class );

		$this->make_sut( $client, $credentials )->refresh_access_token();
	}

	/**
	 * The authorization URL is produced from the configured OAuth client.
	 *
	 * @covers ::get_authorization_url
	 * @covers ::make_oauth_client
	 */
	public function test_get_authorization_url(): void {

		$client = $this->make_oauth_client_mock();
		$client->expects( 'createAuthUrl' )->once()->andReturn( 'https://accounts.google.com/o/oauth2/auth?test' );

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );
		$credentials->allows( 'get_project_credentials' )->andReturn( $this->make_project_credentials() );

		$this->assertSame(
			'https://accounts.google.com/o/oauth2/auth?test',
			$this->make_sut( $client, $credentials )->get_authorization_url()
		);
	}

	/**
	 * An auth code is exchanged for a complete Access_Token.
	 *
	 * @covers ::fetch_access_token_with_auth_code
	 */
	public function test_fetch_access_token_with_auth_code(): void {

		$client = $this->make_oauth_client_mock();
		$client->expects( 'fetchAccessTokenWithAuthCode' )
			->with( 'the-auth-code' )
			->once()
			->andReturn(
				array(
					'access_token'  => 'fresh-access-token',
					'expires_in'    => 3599,
					'scope'         => 'https://www.googleapis.com/auth/gmail.readonly',
					'token_type'    => 'Bearer',
					'created'       => 1700003600,
					'refresh_token' => 'the-refresh-token',
				)
			);

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );
		$credentials->allows( 'get_project_credentials' )->andReturn( $this->make_project_credentials() );

		$result = $this->make_sut( $client, $credentials )->fetch_access_token_with_auth_code( 'the-auth-code' );

		$this->assertInstanceOf( Access_Token::class, $result );
		$this->assertSame( 'fresh-access-token', $result->access_token );
		$this->assertSame( 'the-refresh-token', $result->refresh_token );
	}

	/**
	 * Exchanging an auth code that yields no refresh token is an error.
	 *
	 * @covers ::fetch_access_token_with_auth_code
	 */
	public function test_fetch_access_token_with_auth_code_requires_refresh_token(): void {

		$client = $this->make_oauth_client_mock();
		$client->allows( 'fetchAccessTokenWithAuthCode' )
			->andReturn(
				array(
					'access_token' => 'fresh-access-token',
					'expires_in'   => 3599,
					'token_type'   => 'Bearer',
				)
			);

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );
		$credentials->allows( 'get_project_credentials' )->andReturn( $this->make_project_credentials() );

		$this->expectException( \Exception::class );

		$this->make_sut( $client, $credentials )->fetch_access_token_with_auth_code( 'the-auth-code' );
	}
}

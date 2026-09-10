<?php
/**
 * Unit tests for the Gmail connection refreshing an expired access token while building its API client,
 * and exposing the result through Refreshes_Credentials for the API to save.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\Refreshes_Credentials;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Exception;
use Google_Client;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Email_Connection
 */
class Gmail_Client_Refresh_Unit_Test extends Unit_Testcase {

	private function make_project_credentials(): OAuth_Client_Credentials {
		return new OAuth_Client_Credentials( 'client-id', 'my-project', 'https://auth', 'https://token', 'https://certs', 'client-secret' );
	}

	private function make_existing_token( string $refresh_token = 'the-refresh-token' ): Access_Token {
		return new Access_Token( 'stale-access-token', 3599, 'https://www.googleapis.com/auth/gmail.readonly', 'Bearer', 1700000000, $refresh_token );
	}

	/**
	 * A Google_Client that accepts the configuration calls getClient() makes.
	 */
	private function make_client_mock(): Google_Client {
		$client = Mockery::mock( Google_Client::class );
		foreach ( array( 'setLogger', 'setApplicationName', 'setScopes', 'setAuthConfig', 'setAccessType', 'setPrompt', 'setAccessToken' ) as $method ) {
			$client->allows( $method );
		}
		return $client;
	}

	private function make_credentials( ?Access_Token $token ): Gmail_Credentials {
		return new Gmail_Credentials( $this->make_project_credentials(), $token );
	}

	private function make_sut( Google_Client $client, Google_API_Credentials_Interface $credentials ): Gmail_Email_Connection {
		$sut = Mockery::mock( Gmail_Email_Connection::class, array( Mockery::mock( Email_Account_Settings_Interface::class ), $this->logger ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$sut->allows( 'make_client' )->andReturn( $client );
		$sut->set_credentials( $credentials );
		return $sut;
	}

	/**
	 * @coversNothing
	 */
	public function test_implements_refreshes_credentials(): void {
		$this->assertInstanceOf( Refreshes_Credentials::class, $this->make_sut( $this->make_client_mock(), $this->make_credentials( null ) ) );
	}

	/**
	 * @covers ::getClient
	 * @covers ::get_refreshed_credentials
	 */
	public function test_no_access_token_means_no_client_and_nothing_to_save(): void {
		$sut = $this->make_sut( $this->make_client_mock(), $this->make_credentials( null ) );

		$this->assertNull( $sut->getClient() );
		$this->assertNull( $sut->get_refreshed_credentials() );
	}

	/**
	 * @covers ::getClient
	 * @covers ::get_refreshed_credentials
	 */
	public function test_valid_token_is_used_as_is(): void {
		$client = $this->make_client_mock();
		$client->expects( 'isAccessTokenExpired' )->andReturn( false );
		$client->expects( 'fetchAccessTokenWithRefreshToken' )->never();

		$sut = $this->make_sut( $client, $this->make_credentials( $this->make_existing_token() ) );

		$this->assertSame( $client, $sut->getClient() );
		$this->assertNull( $sut->get_refreshed_credentials(), 'Nothing changed, so nothing to save.' );
	}

	/**
	 * An expired token is refreshed; the new token (with fields Google omits carried over from the old
	 * one) and the same OAuth client are exposed for the API to save, and used for the rest of the request.
	 *
	 * @covers ::getClient
	 * @covers ::get_refreshed_credentials
	 */
	public function test_expired_token_is_refreshed_and_exposed_for_saving(): void {
		$client = $this->make_client_mock();
		$client->expects( 'isAccessTokenExpired' )->andReturn( true );
		$client->expects( 'getRefreshToken' )->andReturn( 'the-refresh-token' );
		$client->expects( 'fetchAccessTokenWithRefreshToken' )
			->with( 'the-refresh-token' )
			->once()
			->andReturn(
				array(
					'access_token' => 'fresh-access-token',
					'expires_in'   => 3599,
					'created'      => 1700003600,
				)
			);

		$credentials = $this->make_credentials( $this->make_existing_token() );
		$sut         = $this->make_sut( $client, $credentials );

		$this->assertSame( $client, $sut->getClient() );

		$refreshed = $sut->get_refreshed_credentials();
		$this->assertInstanceOf( Gmail_Credentials::class, $refreshed );
		$this->assertSame( $credentials->get_project_credentials(), $refreshed->get_project_credentials() );

		$token = $refreshed->get_access_token();
		$this->assertNotNull( $token );
		$this->assertSame( 'fresh-access-token', $token->access_token );
		$this->assertSame( 1700003600, $token->created );
		$this->assertSame( 'the-refresh-token', $token->refresh_token, 'Google omits the refresh token; the existing one is kept.' );
		$this->assertSame( 'Bearer', $token->token_type, 'Omitted fields are carried over from the old token.' );
		$this->assertSame( 'https://www.googleapis.com/auth/gmail.readonly', $token->scope );
	}

	/**
	 * Setting credentials again (e.g. the API re-using the connection) clears the pending refreshed value.
	 *
	 * @covers ::set_credentials
	 * @covers ::get_refreshed_credentials
	 */
	public function test_set_credentials_clears_the_refreshed_credentials(): void {
		$client = $this->make_client_mock();
		$client->allows( 'isAccessTokenExpired' )->andReturn( true );
		$client->allows( 'getRefreshToken' )->andReturn( 'the-refresh-token' );
		$client->allows( 'fetchAccessTokenWithRefreshToken' )->andReturn( array( 'access_token' => 'fresh-access-token' ) );

		$sut = $this->make_sut( $client, $this->make_credentials( $this->make_existing_token() ) );
		$sut->getClient();
		$this->assertNotNull( $sut->get_refreshed_credentials() );

		$sut->set_credentials( $this->make_credentials( $this->make_existing_token() ) );

		$this->assertNull( $sut->get_refreshed_credentials() );
	}

	/**
	 * There is no interactive authorisation on this path: without a refresh token it is an error.
	 *
	 * @covers ::getClient
	 */
	public function test_expired_token_without_a_refresh_token_throws(): void {
		$client = $this->make_client_mock();
		$client->allows( 'isAccessTokenExpired' )->andReturn( true );
		$client->allows( 'getRefreshToken' )->andReturn( null );
		$client->expects( 'fetchAccessTokenWithRefreshToken' )->never();

		$sut = $this->make_sut( $client, $this->make_credentials( $this->make_existing_token( '' ) ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'no refresh token' );
		$sut->getClient();
	}

	/**
	 * @covers ::getClient
	 */
	public function test_refresh_error_response_throws(): void {
		$client = $this->make_client_mock();
		$client->allows( 'isAccessTokenExpired' )->andReturn( true );
		$client->allows( 'getRefreshToken' )->andReturn( 'the-refresh-token' );
		$client->allows( 'fetchAccessTokenWithRefreshToken' )->andReturn( array( 'error' => 'invalid_grant' ) );
		\WP_Mock::passthruFunction( 'esc_html' );

		$sut = $this->make_sut( $client, $this->make_credentials( $this->make_existing_token() ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'invalid_grant' );
		$sut->getClient();
	}
}

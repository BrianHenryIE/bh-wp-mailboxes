<?php
/**
 * Unit tests for parsing Google OAuth client_secret.json.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use RuntimeException;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials
 */
class OAuth_Client_Credentials_Unit_Test extends Unit_Testcase {

	/**
	 * A "Web application" client (top-level `web`, includes javascript_origins).
	 *
	 * @covers ::from_json
	 */
	public function test_from_json_web_client(): void {

		$json = json_decode(
			<<<'JSON'
			{
				"web": {
					"client_id": "web-id.apps.googleusercontent.com",
					"project_id": "my-project",
					"auth_uri": "https://accounts.google.com/o/oauth2/auth",
					"token_uri": "https://oauth2.googleapis.com/token",
					"auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
					"client_secret": "web-secret",
					"redirect_uris": ["https://example.com/oauth2callback"],
					"javascript_origins": ["https://example.com"]
				}
			}
			JSON
		);

		$credentials = OAuth_Client_Credentials::from_json( $json );

		$this->assertSame( 'web-id.apps.googleusercontent.com', $credentials->client_id );
		$this->assertSame( 'web-secret', $credentials->client_secret );
		$this->assertSame( array( 'https://example.com/oauth2callback' ), $credentials->redirect_uris );
		$this->assertSame( array( 'https://example.com' ), $credentials->javascript_origins );
	}

	/**
	 * A "Desktop app" client (top-level `installed`, no javascript_origins) — used for CLI flows that
	 * have no callback URL. javascript_origins defaults to an empty array.
	 *
	 * @covers ::from_json
	 */
	public function test_from_json_installed_client(): void {

		$json = json_decode(
			<<<'JSON'
			{
				"installed": {
					"client_id": "desktop-id.apps.googleusercontent.com",
					"project_id": "my-project",
					"auth_uri": "https://accounts.google.com/o/oauth2/auth",
					"token_uri": "https://oauth2.googleapis.com/token",
					"auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
					"client_secret": "desktop-secret",
					"redirect_uris": ["http://localhost"]
				}
			}
			JSON
		);

		$credentials = OAuth_Client_Credentials::from_json( $json );

		$this->assertSame( 'desktop-id.apps.googleusercontent.com', $credentials->client_id );
		$this->assertSame( 'desktop-secret', $credentials->client_secret );
		$this->assertSame( array( 'http://localhost' ), $credentials->redirect_uris );
		$this->assertSame( array(), $credentials->javascript_origins );
	}

	/**
	 * JSON with neither a `web` nor `installed` client is rejected.
	 *
	 * @covers ::from_json
	 */
	public function test_from_json_rejects_unknown_shape(): void {

		$this->expectException( RuntimeException::class );

		OAuth_Client_Credentials::from_json( (object) array( 'something_else' => array() ) );
	}
}

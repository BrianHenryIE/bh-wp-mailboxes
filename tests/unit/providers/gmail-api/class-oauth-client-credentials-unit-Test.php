<?php
/**
 * Unit tests for parsing Google OAuth client_secret.json.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain json_encode() and temp files are fine in tests.

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

	/**
	 * The URI lists are optional in Google's JSON (a Desktop-app client has neither).
	 *
	 * @covers ::__construct
	 */
	public function test_uri_lists_default_to_empty(): void {
		$sut = new OAuth_Client_Credentials( 'id', 'project', 'https://auth', 'https://token', 'https://certs', 'secret' );

		$this->assertSame( array(), $sut->redirect_uris );
		$this->assertSame( array(), $sut->javascript_origins );
	}

	/**
	 * @covers ::from_file
	 */
	public function test_from_file_reads_a_downloaded_client(): void {
		$file = $this->make_file( '{"installed":{"client_id":"id","project_id":"project","auth_uri":"https://auth","token_uri":"https://token","auth_provider_x509_cert_url":"https://certs","client_secret":"secret","redirect_uris":["http://localhost"]}}' );

		$sut = OAuth_Client_Credentials::from_file( $file );

		$this->assertSame( 'id', $sut->client_id );
		$this->assertSame( 'secret', $sut->client_secret );
		$this->assertSame( array( 'http://localhost' ), $sut->redirect_uris );
	}

	/**
	 * @covers ::from_file
	 */
	public function test_from_file_rejects_invalid_json(): void {
		\WP_Mock::passthruFunction( 'esc_html' );
		$file = $this->make_file( 'not json' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'did not contain valid JSON' );

		OAuth_Client_Credentials::from_file( $file );
	}

	/**
	 * @covers ::from_file
	 */
	public function test_from_file_rejects_an_unreadable_file(): void {
		\WP_Mock::passthruFunction( 'esc_html' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to read credentials file' );

		// Suppress the fopen() warning PHPUnit would otherwise convert into an error before the exception.
		@OAuth_Client_Credentials::from_file( sys_get_temp_dir() . '/bh-wp-mailboxes-missing-' . uniqid() . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * The flat, stored shape (the object's own properties) round-trips, unlike Google's wrapped JSON.
	 *
	 * @covers ::from_array
	 */
	public function test_from_array_round_trips_the_stored_shape(): void {
		$sut = new OAuth_Client_Credentials( 'id', 'project', 'https://auth', 'https://token', 'https://certs', 'secret', array( 'http://localhost' ), array( 'https://example.com' ) );

		$this->assertEquals( $sut, OAuth_Client_Credentials::from_array( get_object_vars( $sut ) ) );
		$this->assertEquals( $sut, OAuth_Client_Credentials::from_array( json_decode( (string) json_encode( get_object_vars( $sut ) ), true ) ) );
	}

	/**
	 * Missing or non-string fields become empty strings, and non-string list entries are dropped.
	 *
	 * @covers ::from_array
	 * @covers ::string_at
	 * @covers ::strings_at
	 */
	public function test_from_array_tolerates_missing_and_malformed_fields(): void {
		$sut = OAuth_Client_Credentials::from_array(
			array(
				'client_id'          => 'id',
				'project_id'         => 5,
				'redirect_uris'      => array( 'http://localhost', 5, null ),
				'javascript_origins' => 'not-a-list',
			)
		);

		$this->assertSame( 'id', $sut->client_id );
		$this->assertSame( '', $sut->project_id );
		$this->assertSame( '', $sut->client_secret );
		$this->assertSame( array( 'http://localhost' ), $sut->redirect_uris );
		$this->assertSame( array(), $sut->javascript_origins );
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

	/**
	 * Write a throwaway JSON file and return its path.
	 *
	 * @param string $contents The file contents.
	 */
	private function make_file( string $contents ): string {
		$dir = sys_get_temp_dir() . '/bh-wp-mailboxes-oauth-' . uniqid();
		mkdir( $dir );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/client_secret.json', $contents );

		return $dir . '/client_secret.json';
	}
}

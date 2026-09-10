<?php
/**
 * Unit tests for the value object pairing a Google project's `installed` and `web` OAuth clients.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Error;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Client_Secret
 */
class Client_Secret_Unit_Test extends Unit_Testcase {

	private function make_client( string $client_id ): OAuth_Client_Credentials {
		return new OAuth_Client_Credentials( $client_id, 'project', 'https://auth', 'https://token', 'https://certs', 'secret' );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_exposes_both_clients(): void {
		$installed = $this->make_client( 'installed-id' );
		$web       = $this->make_client( 'web-id' );

		$sut = new Client_Secret( $installed, $web );

		$this->assertSame( $installed, $sut->installed );
		$this->assertSame( $web, $sut->web );
	}

	/**
	 * The two clients can be built from the two shapes Google downloads (`installed` / `web`).
	 *
	 * @covers ::__construct
	 */
	public function test_pairs_the_two_downloaded_client_shapes(): void {
		$installed = OAuth_Client_Credentials::from_json( json_decode( '{"installed":{"client_id":"installed-id","project_id":"p","auth_uri":"a","token_uri":"t","auth_provider_x509_cert_url":"c","client_secret":"s"}}' ) );
		$web       = OAuth_Client_Credentials::from_json( json_decode( '{"web":{"client_id":"web-id","project_id":"p","auth_uri":"a","token_uri":"t","auth_provider_x509_cert_url":"c","client_secret":"s","redirect_uris":["https://example.com/oauth2callback"]}}' ) );

		$sut = new Client_Secret( $installed, $web );

		$this->assertSame( 'installed-id', $sut->installed->client_id );
		$this->assertSame( 'web-id', $sut->web->client_id );
		$this->assertSame( array( 'https://example.com/oauth2callback' ), $sut->web->redirect_uris );
	}

	/**
	 * It is a readonly value object.
	 *
	 * @coversNothing
	 */
	public function test_is_readonly(): void {
		$sut = new Client_Secret( $this->make_client( 'installed-id' ), $this->make_client( 'web-id' ) );

		$this->expectException( Error::class );
		$this->expectExceptionMessage( 'readonly' );

		$sut->web = $this->make_client( 'other' ); // @phpstan-ignore-line -- The write is the point of the test.
	}
}

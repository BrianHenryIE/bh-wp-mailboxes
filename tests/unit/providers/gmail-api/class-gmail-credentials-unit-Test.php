<?php
/**
 * Unit tests for the Gmail credentials value object.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

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

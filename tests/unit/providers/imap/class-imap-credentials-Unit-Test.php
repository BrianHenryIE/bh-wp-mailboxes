<?php
/**
 * Unit tests for the IMAP credentials value object and its stored representation.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain json_encode() and temp files are fine in tests.

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use InvalidArgumentException;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials
 */
class Imap_Credentials_Unit_Test extends Unit_Testcase {

	/**
	 * @covers ::__construct
	 * @covers ::get_email_imap_server
	 * @covers ::get_email_account_username
	 * @covers ::get_email_account_password
	 * @covers ::get_encryption
	 */
	public function test_getters_and_public_properties(): void {
		$sut = new Imap_Credentials( 'imap.example.com:993', 'user', 'p<a&ss"word', 'STARTTLS' );

		$this->assertSame( 'imap.example.com:993', $sut->get_email_imap_server() );
		$this->assertSame( 'user', $sut->get_email_account_username() );
		$this->assertSame( 'p<a&ss"word', $sut->get_email_account_password() );
		$this->assertSame( 'STARTTLS', $sut->get_encryption() );
		$this->assertSame( 'imap.example.com:993', $sut->server );
		$this->assertSame( 'TLS', ( new Imap_Credentials( 's', 'u', 'p' ) )->get_encryption(), 'TLS by default.' );
	}

	/**
	 * The stored representation (the trait, through the getters) round-trips through from_array(),
	 * keeping "no encryption" as an empty string.
	 *
	 * @covers ::jsonSerialize
	 * @covers ::from_array
	 */
	public function test_json_round_trip(): void {
		$sut = new Imap_Credentials( 'imap.example.com:993', 'user', 'p<a&ss"word', '' );

		$this->assertSame(
			array(
				'type'       => 'imap',
				'server'     => 'imap.example.com:993',
				'username'   => 'user',
				'password'   => 'p<a&ss"word',
				'encryption' => '',
			),
			$sut->jsonSerialize()
		);

		$this->assertEquals( $sut, Imap_Credentials::from_array( json_decode( json_encode( $sut ), true ) ) );
	}

	/**
	 * Only a record with no `encryption` key at all gets the TLS default; missing strings become empty.
	 *
	 * @covers ::from_array
	 */
	public function test_from_array_defaults(): void {
		$rebuilt = Imap_Credentials::from_array(
			array(
				'server'   => 's',
				'username' => 5,
			)
		);

		$this->assertSame( 's', $rebuilt->server );
		$this->assertSame( '', $rebuilt->username );
		$this->assertSame( '', $rebuilt->password );
		$this->assertSame( 'TLS', $rebuilt->encryption );
	}

	/**
	 * @covers ::from_array
	 */
	public function test_from_array_rejects_other_types(): void {
		$this->expectException( InvalidArgumentException::class );

		Imap_Credentials::from_array( array( 'type' => 'gmail' ) );
	}
}

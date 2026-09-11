<?php

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Imap\Imap_Credentials_Env
 */
class Imap_Credentials_Env_Unit_Test extends Unit_Testcase {

	/** @var array<string,mixed> */
	private array $original_env;

	protected function setup(): void {
		parent::setup();
		$this->original_env = $_ENV; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	#[\Override]
	protected function tearDown(): void {
		$_ENV = $this->original_env; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		parent::tearDown();
	}

	/**
	 * @covers ::__construct
	 * @covers ::get_email_imap_server
	 * @covers ::get_email_account_username
	 * @covers ::get_email_account_password
	 * @covers ::get_encryption
	 */
	public function test_all_env_vars_present_returns_correct_values(): void {
		$_ENV['IMAP_SERVER']     = 'imap.example.com';
		$_ENV['IMAP_USERNAME']   = 'user@example.com';
		$_ENV['IMAP_PASSWORD']   = 'secret';
		$_ENV['IMAP_ENCRYPTION'] = 'TLS';

		$sut = new Imap_Credentials_Env();

		$this->assertSame( 'imap.example.com', $sut->get_email_imap_server() );
		$this->assertSame( 'user@example.com', $sut->get_email_account_username() );
		$this->assertSame( 'secret', $sut->get_email_account_password() );
		$this->assertSame( 'TLS', $sut->get_encryption() );
		$this->assertTrue( $sut->should_validate_cert(), 'Validates when IMAP_VALIDATE_CERT is unset.' );
	}

	/**
	 * Only an explicit false-like value turns certificate validation off.
	 *
	 * @covers ::__construct
	 * @covers ::should_validate_cert
	 */
	public function test_validate_cert_env_var(): void {
		$_ENV['IMAP_SERVER']   = 'imap.example.com';
		$_ENV['IMAP_USERNAME'] = 'user@example.com';
		$_ENV['IMAP_PASSWORD'] = 'secret';

		foreach ( array( 'false', '0', 'no', 'off', 'FALSE' ) as $off ) {
			$_ENV['IMAP_VALIDATE_CERT'] = $off;
			$this->assertFalse( ( new Imap_Credentials_Env() )->should_validate_cert(), "IMAP_VALIDATE_CERT={$off}" );
		}
		foreach ( array( 'true', '1', 'yes', 'on', '', 'anything' ) as $on ) {
			$_ENV['IMAP_VALIDATE_CERT'] = $on;
			$this->assertTrue( ( new Imap_Credentials_Env() )->should_validate_cert(), "IMAP_VALIDATE_CERT={$on}" );
		}
		unset( $_ENV['IMAP_VALIDATE_CERT'] );
	}

	/**
	 * @covers ::__construct
	 */
	public function test_custom_env_var_key_names_read_correct_env_entries(): void {
		$_ENV['MY_IMAP_HOST']     = 'mail.custom.org';
		$_ENV['MY_IMAP_USER']     = 'custom_user';
		$_ENV['MY_IMAP_PASS']     = 'custom_pass';
		$_ENV['MY_IMAP_ENC']      = 'STARTTLS';
		$_ENV['MY_IMAP_VALIDATE'] = 'false';

		$sut = new Imap_Credentials_Env( 'MY_IMAP_HOST', 'MY_IMAP_USER', 'MY_IMAP_PASS', 'MY_IMAP_ENC', 'MY_IMAP_VALIDATE' );

		$this->assertSame( 'mail.custom.org', $sut->get_email_imap_server() );
		$this->assertSame( 'custom_user', $sut->get_email_account_username() );
		$this->assertSame( 'custom_pass', $sut->get_email_account_password() );
		$this->assertSame( 'STARTTLS', $sut->get_encryption() );
		$this->assertFalse( $sut->should_validate_cert() );
		unset( $_ENV['MY_IMAP_VALIDATE'] );
	}

	/**
	 * @covers ::__construct
	 * @covers ::validate
	 */
	public function test_throws_when_server_env_var_absent(): void {
		unset( $_ENV['IMAP_SERVER'] );
		$_ENV['IMAP_USERNAME']   = 'user@example.com';
		$_ENV['IMAP_PASSWORD']   = 'secret';
		$_ENV['IMAP_ENCRYPTION'] = 'TLS';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/IMAP_SERVER/' );

		new Imap_Credentials_Env();
	}

	/**
	 * @covers ::__construct
	 * @covers ::validate
	 */
	public function test_throws_when_username_env_var_absent(): void {
		$_ENV['IMAP_SERVER'] = 'imap.example.com';
		unset( $_ENV['IMAP_USERNAME'] );
		$_ENV['IMAP_PASSWORD']   = 'secret';
		$_ENV['IMAP_ENCRYPTION'] = 'TLS';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/IMAP_USERNAME/' );

		new Imap_Credentials_Env();
	}

	/**
	 * @covers ::__construct
	 * @covers ::validate
	 */
	public function test_throws_when_password_env_var_absent(): void {
		$_ENV['IMAP_SERVER']   = 'imap.example.com';
		$_ENV['IMAP_USERNAME'] = 'user@example.com';
		unset( $_ENV['IMAP_PASSWORD'] );
		$_ENV['IMAP_ENCRYPTION'] = 'TLS';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/IMAP_PASSWORD/' );

		new Imap_Credentials_Env();
	}

	/**
	 * @covers ::__construct
	 * @covers ::validate
	 */
	public function test_singular_message_form_when_one_credential_missing(): void {
		$_ENV['IMAP_SERVER']   = 'imap.example.com';
		$_ENV['IMAP_USERNAME'] = 'user@example.com';
		unset( $_ENV['IMAP_PASSWORD'] );
		$_ENV['IMAP_ENCRYPTION'] = 'TLS';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/credential\s.*\bis\b/i' );

		new Imap_Credentials_Env();
	}

	/**
	 * @covers ::__construct
	 * @covers ::validate
	 */
	public function test_plural_message_form_when_multiple_credentials_missing(): void {
		unset( $_ENV['IMAP_SERVER'] );
		unset( $_ENV['IMAP_USERNAME'] );
		$_ENV['IMAP_PASSWORD']   = 'secret';
		$_ENV['IMAP_ENCRYPTION'] = 'TLS';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/credentials\s.*\bare\b/i' );

		new Imap_Credentials_Env();
	}

	/**
	 * @covers ::__construct
	 * @covers ::validate
	 * @covers ::get_encryption
	 */
	public function test_missing_encryption_env_var_does_not_throw(): void {
		$_ENV['IMAP_SERVER']   = 'imap.example.com';
		$_ENV['IMAP_USERNAME'] = 'user@example.com';
		$_ENV['IMAP_PASSWORD'] = 'secret';
		unset( $_ENV['IMAP_ENCRYPTION'] );

		$sut = new Imap_Credentials_Env();

		$this->assertSame( '', $sut->get_encryption() );
	}

	/**
	 * The stored representation captures the values read from the environment, not the variable names.
	 *
	 * @covers ::jsonSerialize
	 */
	public function test_json_serialize_captures_values(): void {
		$_ENV['IMAP_SERVER']     = 'imap.example.com';
		$_ENV['IMAP_USERNAME']   = 'user@example.com';
		$_ENV['IMAP_PASSWORD']   = 'secret';
		$_ENV['IMAP_ENCRYPTION'] = 'STARTTLS';
		unset( $_ENV['IMAP_VALIDATE_CERT'] );

		$this->assertSame(
			array(
				'type'          => 'imap',
				'server'        => 'imap.example.com',
				'username'      => 'user@example.com',
				'password'      => 'secret',
				'encryption'    => 'STARTTLS',
				'validate_cert' => true,
			),
			( new Imap_Credentials_Env() )->jsonSerialize()
		);
	}
}

<?php
/**
 * Unit tests for the ImapEngine mailbox configuration built from the credentials, and the filter over it.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DirectoryTree\ImapEngine\Mailbox;
use Mockery;
use WP_Mock;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection
 */
class ImapEngine_Imap_Email_Connection_Config_Unit_Test extends Unit_Testcase {

	/**
	 * The configuration the connection built its Mailbox with.
	 *
	 * @param ImapEngine_Imap_Email_Connection $sut The connection after set_credentials().
	 *
	 * @return array<string,mixed>
	 */
	private function mailbox_config( ImapEngine_Imap_Email_Connection $sut ): array {
		$mailbox = new \ReflectionProperty( ImapEngine_Imap_Email_Connection::class, 'mailbox' )->getValue( $sut );
		$this->assertInstanceOf( Mailbox::class, $mailbox );

		/** @var array<string,mixed> $config */
		$config = new \ReflectionProperty( Mailbox::class, 'config' )->getValue( $mailbox );
		return $config;
	}

	/**
	 * Expect the config filter with exact arguments (WP_Mock matches filter arguments by value / identity)
	 * and reply with the given array, or the input when none is given.
	 *
	 * @param array<string,mixed>              $config      The configuration the connection is expected to build.
	 * @param string                           $plugin_slug The slug the connection was constructed with.
	 * @param IMAP_Credentials_Interface       $credentials The credentials passed to set_credentials().
	 * @param Email_Account_Settings_Interface $account     The account the connection was constructed with.
	 * @param ?array<string,mixed>             $reply       What the filter returns; null to return `$config`.
	 */
	private function expect_config_filter( array $config, string $plugin_slug, IMAP_Credentials_Interface $credentials, Email_Account_Settings_Interface $account, ?array $reply = null ): void {
		WP_Mock::onFilter( 'bh_wp_mailboxes_imap_mailbox_config' )
			->with( $config, $plugin_slug, $credentials, $account )
			->reply( $reply ?? $config );
	}

	/**
	 * @covers ::set_credentials
	 */
	public function test_config_validates_the_certificate_by_default(): void {
		$account     = Mockery::mock( Email_Account_Settings_Interface::class );
		$credentials = new Imap_Credentials( 'imap.example.com', 'user', 'pw' );
		$this->expect_config_filter(
			array(
				'host'          => 'imap.example.com',
				'port'          => 993,
				'username'      => 'user',
				'password'      => 'pw',
				'encryption'    => 'TLS',
				'validate_cert' => true,
			),
			'',
			$credentials,
			$account
		);

		$sut = new ImapEngine_Imap_Email_Connection( $account, $this->logger );
		$sut->set_credentials( $credentials );

		$config = $this->mailbox_config( $sut );
		$this->assertSame( 'imap.example.com', $config['host'] );
		$this->assertSame( 993, $config['port'] );
		$this->assertSame( 'TLS', $config['encryption'] );
		$this->assertTrue( $config['validate_cert'] );
		$this->assertSame( 'user', $config['username'] );
		$this->assertSame( 'pw', $config['password'] );
	}

	/**
	 * @covers ::set_credentials
	 */
	public function test_config_can_skip_certificate_validation(): void {
		$account     = Mockery::mock( Email_Account_Settings_Interface::class );
		$credentials = new Imap_Credentials( 'imap.example.com:1143', 'user', 'pw', '', false );
		$this->expect_config_filter(
			array(
				'host'          => 'imap.example.com',
				'port'          => 1143,
				'username'      => 'user',
				'password'      => 'pw',
				'encryption'    => '',
				'validate_cert' => false,
			),
			'',
			$credentials,
			$account
		);

		$sut = new ImapEngine_Imap_Email_Connection( $account, $this->logger );
		$sut->set_credentials( $credentials );

		$config = $this->mailbox_config( $sut );
		$this->assertSame( 1143, $config['port'], 'The :port in the server overrides the encryption default.' );
		$this->assertSame( '', $config['encryption'] );
		$this->assertFalse( $config['validate_cert'] );
	}

	/**
	 * STARTTLS is a plain connection on 143 upgraded after connecting, not implicit TLS on 993.
	 *
	 * @see https://github.com/BrianHenryIE/bh-wp-mailboxes/issues/104
	 *
	 * @covers ::set_credentials
	 */
	public function test_config_starttls_is_passed_through_on_port_143(): void {
		$account     = Mockery::mock( Email_Account_Settings_Interface::class );
		$credentials = new Imap_Credentials( 'imap.example.com', 'user', 'pw', 'STARTTLS' );
		$this->expect_config_filter(
			array(
				'host'          => 'imap.example.com',
				'port'          => 143,
				'username'      => 'user',
				'password'      => 'pw',
				'encryption'    => 'starttls',
				'validate_cert' => true,
			),
			'',
			$credentials,
			$account
		);

		$sut = new ImapEngine_Imap_Email_Connection( $account, $this->logger );
		$sut->set_credentials( $credentials );

		$config = $this->mailbox_config( $sut );
		$this->assertSame( 'starttls', $config['encryption'] );
		$this->assertSame( 143, $config['port'] );
	}

	/**
	 * The filter receives the full configuration, the consumer's plugin slug, the credentials and the
	 * account, and its return value is what the mailbox is built with (e.g. adding `debug`).
	 *
	 * @covers ::set_credentials
	 * @covers ::__construct
	 */
	public function test_config_filter_receives_context_and_its_result_is_used(): void {
		$account     = Mockery::mock( Email_Account_Settings_Interface::class );
		$credentials = new Imap_Credentials( 'imap.example.com', 'user', 'pw', 'TLS', false );

		$expected = array(
			'host'          => 'imap.example.com',
			'port'          => 993,
			'username'      => 'user',
			'password'      => 'pw',
			'encryption'    => 'TLS',
			'validate_cert' => false,
		);
		// Matching on the exact array, slug, credentials and account proves the filter received them all.
		$this->expect_config_filter(
			$expected,
			'my-plugin',
			$credentials,
			$account,
			array_merge(
				$expected,
				array(
					'debug'   => true,
					'timeout' => 5,
				)
			)
		);

		$sut = new ImapEngine_Imap_Email_Connection( $account, $this->logger, 'my-plugin' );
		$sut->set_credentials( $credentials );

		$config = $this->mailbox_config( $sut );
		$this->assertTrue( $config['debug'] );
		$this->assertSame( 5, $config['timeout'] );
		$this->assertFalse( $config['validate_cert'] );
	}
}

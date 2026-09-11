<?php
/**
 * Unit tests for how the ImapEngine connection describes a failure to connect.
 *
 * ImapEngine reports e.g. "Unable to connect to tls://host:993 ()" — the server address and PHP's
 * `$errstr`, which is empty when PHP could not even try (no sockets, as in WordPress Playground).
 * The wrapper appends the server settings used, the PHP warning ImapEngine suppressed, the error
 * code, and a hint when there was no reason at all.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTimeImmutable;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use DirectoryTree\ImapEngine\Mailbox;
use Mockery;
use RuntimeException;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Imap\ImapEngine_Imap_Email_Connection
 */
class ImapEngine_Imap_Email_Connection_Failure_Unit_Test extends Unit_Testcase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::passthruFunction( '__' );
	}

	/**
	 * A connection configured with credentials (records the server settings; no I/O), with the
	 * ImapEngine Mailbox then swapped for a mock.
	 *
	 * @param Mailbox $mailbox    The mock to inject.
	 * @param string  $server     The IMAP server (with optional :port) in the credentials.
	 * @param string  $encryption The encryption in the credentials.
	 */
	private function make_sut( Mailbox $mailbox, string $server = 'mail.example.com', string $encryption = 'TLS' ): ImapEngine_Imap_Email_Connection {
		$sut = new ImapEngine_Imap_Email_Connection( Mockery::mock( Email_Account_Settings_Interface::class ), $this->logger );
		$sut->set_credentials( new Imap_Credentials( $server, 'user', 'pw', $encryption ) );

		$property = new \ReflectionProperty( ImapEngine_Imap_Email_Connection::class, 'mailbox' );
		$property->setValue( $sut, $mailbox );

		return $sut;
	}

	/**
	 * A Mailbox whose connect() throws ImapEngine's failure, optionally after raising a suppressed PHP warning.
	 *
	 * @param string  $message     The exception message.
	 * @param int     $code        The exception code (errno).
	 * @param ?string $php_warning A warning to raise first, as `@stream_socket_client()` would.
	 */
	private function failing_mailbox( string $message, int $code = 0, ?string $php_warning = null ): Mailbox {
		$mailbox = Mockery::mock( Mailbox::class );
		$mailbox->allows( 'connect' )->andReturnUsing(
			function () use ( $message, $code, $php_warning ): void {
				if ( ! is_null( $php_warning ) ) {
					// ImapEngine's `@stream_socket_client()` suppresses the warning, but user error handlers still see it.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.Security.EscapeOutput.OutputNotEscaped
					@trigger_error( $php_warning, E_USER_WARNING );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new ImapConnectionFailedException( $message, $code );
			}
		);
		return $mailbox;
	}

	/**
	 * With no reason from PHP (the Playground case), the message explains that PHP could not open a socket.
	 *
	 * @covers ::connect
	 * @covers ::describe_connection_failure
	 * @covers ::test_connection
	 */
	public function test_no_reason_from_php_explains_no_socket(): void {
		$original = 'Unable to connect to tls://mail.example.com:993 ()';
		$sut      = $this->make_sut( $this->failing_mailbox( $original ) );

		try {
			$sut->test_connection();
			$this->fail( 'Expected an ImapConnectionFailedException.' );
		} catch ( ImapConnectionFailedException $exception ) {
			$this->assertStringStartsWith( $original . '.', $exception->getMessage() );
			$this->assertStringContainsString( 'Server: mail.example.com:993, encryption: TLS.', $exception->getMessage() );
			$this->assertStringContainsString( 'PHP could not open a network socket', $exception->getMessage() );
			$this->assertStringContainsString( 'WordPress Playground', $exception->getMessage() );
			$this->assertStringNotContainsString( 'PHP:', $exception->getMessage() );
			$this->assertStringNotContainsString( 'Error code', $exception->getMessage() );
			$this->assertSame( $original, $exception->getPrevious()?->getMessage(), 'The original is kept as the previous exception.' );
		}
	}

	/**
	 * The warning PHP raised (which ImapEngine silences with `@`) is recovered and shown instead of the hint.
	 *
	 * @covers ::connect
	 * @covers ::describe_connection_failure
	 */
	public function test_suppressed_php_warning_is_recovered(): void {
		$sut = $this->make_sut(
			$this->failing_mailbox(
				'Unable to connect to tls://mail.example.com:993 ()',
				0,
				'stream_socket_client(): Unable to find the socket transport "tls" - did you forget to enable it when you configured PHP?'
			)
		);

		try {
			$sut->test_connection();
			$this->fail( 'Expected an ImapConnectionFailedException.' );
		} catch ( ImapConnectionFailedException $exception ) {
			$this->assertStringContainsString( 'PHP: Unable to find the socket transport "tls" - did you forget to enable it when you configured PHP?.', $exception->getMessage() );
			$this->assertStringNotContainsString( 'stream_socket_client():', $exception->getMessage(), 'The function-name prefix is trimmed.' );
			$this->assertStringNotContainsString( 'PHP could not open a network socket', $exception->getMessage() );
		}
	}

	/**
	 * A refused connection carries PHP's reason and the errno, which ImapEngine only puts in the code.
	 *
	 * @covers ::connect
	 * @covers ::describe_connection_failure
	 */
	public function test_refused_connection_shows_reason_and_error_code(): void {
		$sut = $this->make_sut( $this->failing_mailbox( 'Unable to connect to tcp://127.0.0.1:1 (Connection refused)', 111 ), '127.0.0.1:1', '' );

		try {
			$sut->test_connection();
			$this->fail( 'Expected an ImapConnectionFailedException.' );
		} catch ( ImapConnectionFailedException $exception ) {
			$this->assertSame(
				'Unable to connect to tcp://127.0.0.1:1 (Connection refused). Server: 127.0.0.1:1, encryption: none. Error code 111.',
				$exception->getMessage()
			);
			$this->assertSame( 111, $exception->getCode() );
		}
	}

	/**
	 * Only ImapEngine's connection failure is rewrapped; anything else propagates untouched.
	 *
	 * @covers ::connect
	 */
	public function test_other_exceptions_pass_through(): void {
		$mailbox = Mockery::mock( Mailbox::class );
		$mailbox->allows( 'connect' )->andThrow( new RuntimeException( 'AUTHENTICATIONFAILED' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'AUTHENTICATIONFAILED' );
		$this->make_sut( $mailbox )->test_connection();
	}

	/**
	 * The temporary error handler is removed again whether the connect succeeds or fails.
	 *
	 * @covers ::connect
	 */
	public function test_error_handler_is_restored(): void {
		$before = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Reading the current handler.
		restore_error_handler();

		$ok = Mockery::mock( Mailbox::class );
		$ok->allows( 'connect' );
		$this->assertTrue( $this->make_sut( $ok )->test_connection() );

		try {
			$this->make_sut( $this->failing_mailbox( 'Unable to connect to tls://mail.example.com:993 ()' ) )->test_connection();
		} catch ( ImapConnectionFailedException $exception ) {
			$this->assertStringContainsString( 'Server:', $exception->getMessage() );
		}

		$after = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Reading the current handler.
		restore_error_handler();
		$this->assertSame( $before, $after );
	}

	/**
	 * Fetching goes through the same wrapper as the connection test.
	 *
	 * @covers ::retrieve_emails
	 * @covers ::connect
	 */
	public function test_retrieve_emails_describes_the_failure_too(): void {
		$sut = $this->make_sut( $this->failing_mailbox( 'Unable to connect to tls://mail.example.com:993 ()' ) );

		$this->expectException( ImapConnectionFailedException::class );
		$this->expectExceptionMessage( 'Server: mail.example.com:993, encryption: TLS.' );
		$sut->retrieve_emails( new DateTimeImmutable( '-1 day' ) );
	}

	/**
	 * @covers ::is_php_wasm
	 */
	public function test_is_php_wasm_reads_server_software(): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Arranging the superglobal the method reads.
		$previous = $_SERVER['SERVER_SOFTWARE'] ?? null;

		$_SERVER['SERVER_SOFTWARE'] = 'PHP.wasm';
		$this->assertTrue( ImapEngine_Imap_Email_Connection::is_php_wasm() );

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
		$this->assertFalse( ImapEngine_Imap_Email_Connection::is_php_wasm() );

		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertFalse( ImapEngine_Imap_Email_Connection::is_php_wasm() );

		if ( ! is_null( $previous ) ) {
			$_SERVER['SERVER_SOFTWARE'] = $previous;
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}
}

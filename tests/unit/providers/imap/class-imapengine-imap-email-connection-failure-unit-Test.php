<?php
/**
 * Unit tests for how the ImapEngine connection describes a failure to connect.
 *
 * ImapEngine reports e.g. "Unable to connect to tls://host:993 ()" — the server address and PHP's
 * `$errstr`, which is empty when PHP could not even try (no sockets, as in WordPress Playground).
 * The wrapper says which server could not be reached, what that most likely means and what to change,
 * then the details: ImapEngine's message or the fuller PHP warning it suppressed, and the error code.
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
	 * Run the connection test and return the failure message.
	 *
	 * @param ImapEngine_Imap_Email_Connection $sut The connection, whose mailbox fails.
	 */
	private function get_failure( ImapEngine_Imap_Email_Connection $sut ): ImapConnectionFailedException {
		try {
			$sut->test_connection();
		} catch ( ImapConnectionFailedException $exception ) {
			return $exception;
		}
		$this->fail( 'Expected an ImapConnectionFailedException.' );
	}

	/**
	 * Run a callback with PHP's `SERVER_SOFTWARE` set, as WordPress Playground ("PHP.wasm") or a normal server reports it.
	 *
	 * @param string   $server_software The value to set.
	 * @param callable $callback        What to run.
	 */
	private function with_server_software( string $server_software, callable $callback ): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Arranging the superglobal the code reads.
		$previous                   = $_SERVER['SERVER_SOFTWARE'] ?? null;
		$_SERVER['SERVER_SOFTWARE'] = $server_software;
		try {
			$callback();
		} finally {
			if ( is_null( $previous ) ) {
				unset( $_SERVER['SERVER_SOFTWARE'] );
			} else {
				$_SERVER['SERVER_SOFTWARE'] = $previous;
			}
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * With no reason from PHP, the message says which server could not be reached and that PHP could not open a
	 * socket, then gives ImapEngine's message as the details.
	 *
	 * @covers ::connect
	 * @covers ::describe_connection_failure
	 * @covers ::get_failure_details
	 * @covers ::get_failure_hint
	 * @covers ::test_connection
	 */
	public function test_no_reason_from_php_explains_no_socket(): void {
		$original = 'Unable to connect to tls://mail.example.com:993 ()';

		$this->with_server_software(
			'Apache/2.4',
			function () use ( $original ): void {
				$exception = $this->get_failure( $this->make_sut( $this->failing_mailbox( $original ) ) );

				$this->assertSame(
					'Could not connect to mail.example.com on port 993 (TLS). PHP could not open a network socket and gave no reason. This environment may not allow outbound connections, or a firewall may be blocking the port. Details: Unable to connect to tls://mail.example.com:993 ().',
					$exception->getMessage()
				);
				$this->assertSame( $original, $exception->getPrevious()?->getMessage(), 'The original is kept as the previous exception.' );
			}
		);
	}

	/**
	 * In WordPress Playground PHP reports "Unknown error": the message still explains Playground, and PHP's
	 * warning (the same message with the reason filled in) replaces ImapEngine's rather than repeating it.
	 *
	 * Regression: the Playground explanation was only given when PHP gave no reason at all (#150).
	 *
	 * @covers ::describe_connection_failure
	 * @covers ::get_failure_details
	 * @covers ::get_failure_hint
	 */
	public function test_wordpress_playground_is_explained_whatever_php_reports(): void {
		$this->with_server_software(
			'PHP.wasm',
			function (): void {
				$sut = $this->make_sut(
					$this->failing_mailbox(
						'Unable to connect to tls://imap.example.com:993 ()',
						0,
						'stream_socket_client(): Unable to connect to tls://imap.example.com:993 (Unknown error)'
					),
					'imap.example.com:993'
				);

				$message = $this->get_failure( $sut )->getMessage();

				$this->assertSame(
					'Could not connect to imap.example.com on port 993 (TLS). This site is running in WordPress Playground (PHP as WebAssembly in the browser), which cannot connect to mail servers. The account will work on a normal WordPress install. Details: Unable to connect to tls://imap.example.com:993 (Unknown error).',
					$message
				);
				$this->assertSame( 1, substr_count( $message, 'Unable to connect to' ), 'The address is not repeated.' );
			}
		);
	}

	/**
	 * In WordPress Playground with no warning at all, the Playground explanation is given too.
	 *
	 * @covers ::get_failure_hint
	 */
	public function test_wordpress_playground_is_explained_without_a_warning(): void {
		$this->with_server_software(
			'PHP.wasm',
			function (): void {
				$message = $this->get_failure( $this->make_sut( $this->failing_mailbox( 'Unable to connect to tls://mail.example.com:993 ()' ) ) )->getMessage();

				$this->assertStringContainsString( 'This site is running in WordPress Playground', $message );
				$this->assertStringNotContainsString( 'PHP could not open a network socket', $message );
			}
		);
	}

	/**
	 * Outside Playground, "Unknown error" is treated like no reason at all.
	 *
	 * @covers ::get_failure_hint
	 */
	public function test_unknown_error_outside_playground_explains_no_socket(): void {
		$this->with_server_software(
			'Apache/2.4',
			function (): void {
				$sut = $this->make_sut(
					$this->failing_mailbox(
						'Unable to connect to tls://mail.example.com:993 ()',
						0,
						'stream_socket_client(): Unable to connect to tls://mail.example.com:993 (Unknown error)'
					)
				);

				$message = $this->get_failure( $sut )->getMessage();

				$this->assertStringContainsString( 'PHP could not open a network socket and gave no reason.', $message );
				$this->assertStringNotContainsString( 'WordPress Playground', $message );
			}
		);
	}

	/**
	 * A suppressed PHP warning that says something different is added after ImapEngine's message.
	 *
	 * @covers ::connect
	 * @covers ::get_failure_details
	 */
	public function test_suppressed_php_warning_is_recovered(): void {
		$sut = $this->make_sut(
			$this->failing_mailbox(
				'Unable to connect to tls://mail.example.com:993 ()',
				0,
				'stream_socket_client(): Unable to find the socket transport "tls" - did you forget to enable it when you configured PHP?'
			)
		);

		$message = $this->get_failure( $sut )->getMessage();

		$this->assertStringContainsString( 'Details: Unable to connect to tls://mail.example.com:993 (). PHP: Unable to find the socket transport "tls" - did you forget to enable it when you configured PHP?.', $message );
		$this->assertStringNotContainsString( 'stream_socket_client():', $message, 'The function-name prefix is trimmed.' );
		$this->assertStringNotContainsString( 'PHP could not open a network socket', $message, 'PHP gave a reason.' );
	}

	/**
	 * A refused connection says what to check, with PHP's reason and the errno (which ImapEngine only puts in the code).
	 *
	 * @covers ::connect
	 * @covers ::describe_connection_failure
	 * @covers ::get_failure_hint
	 */
	public function test_refused_connection_shows_hint_reason_and_error_code(): void {
		$exception = $this->get_failure( $this->make_sut( $this->failing_mailbox( 'Unable to connect to tcp://127.0.0.1:1 (Connection refused)', 111 ), '127.0.0.1:1', '' ) );

		$this->assertSame(
			'Could not connect to 127.0.0.1 on port 1 (no encryption). The server refused the connection. Check the server name and port, and that IMAP access is enabled for the account. Details: Unable to connect to tcp://127.0.0.1:1 (Connection refused). Error code 111.',
			$exception->getMessage()
		);
		$this->assertSame( 111, $exception->getCode() );
	}

	/**
	 * Common failures and what the message suggests.
	 *
	 * @return array<string, array{0: string, 1: ?string, 2: string}> ImapEngine's message, PHP's warning, and the expected hint.
	 */
	public static function hint_provider(): array {
		return array(
			'timeout'          => array( 'Unable to connect to tls://mail.example.com:993 (Connection timed out)', null, 'The connection timed out. Check the server name and port; a firewall may be blocking the connection.' ),
			'unknown host'     => array( 'Unable to connect to tls://mial.example.com:993 ()', 'stream_socket_client(): php_network_getaddresses: getaddrinfo for mial.example.com failed: Name or service not known', 'The server name could not be found. Check it is spelled correctly.' ),
			'certificate'      => array( 'Unable to connect to tls://mail.example.com:993 ()', 'stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed', 'The server\'s certificate could not be verified. If the server uses a self-signed certificate, untick "Validate the server\'s certificate".' ),
			'encryption, port' => array( 'Unable to connect to tls://mail.example.com:143 ()', 'stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A00010B:SSL routines::wrong version number', 'The encryption setting may not match the port: port 993 normally uses TLS, and port 143 uses STARTTLS or no encryption.' ),
		);
	}

	/**
	 * @dataProvider hint_provider
	 *
	 * @covers ::get_failure_hint
	 *
	 * @param string  $message     ImapEngine's message.
	 * @param ?string $php_warning The warning PHP raised.
	 * @param string  $hint        The expected hint.
	 */
	public function test_common_failures_say_what_to_check( string $message, ?string $php_warning, string $hint ): void {
		$this->with_server_software(
			'Apache/2.4',
			function () use ( $message, $php_warning, $hint ): void {
				$failure = $this->get_failure( $this->make_sut( $this->failing_mailbox( $message, 0, $php_warning ) ) )->getMessage();

				$this->assertStringContainsString( " {$hint} Details: ", $failure );
			}
		);
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

		$this->assertStringContainsString( 'Details:', $this->get_failure( $this->make_sut( $this->failing_mailbox( 'Unable to connect to tls://mail.example.com:993 ()' ) ) )->getMessage() );

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
		$this->expectExceptionMessage( 'Could not connect to mail.example.com on port 993 (TLS).' );
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

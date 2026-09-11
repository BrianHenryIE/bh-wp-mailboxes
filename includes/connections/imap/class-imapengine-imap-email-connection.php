<?php
/**
 * Connects to IMAP server and returns an array of emails.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3501
 * @see https://www.rfc-editor.org/info/rfc1176
 *
 * @package    brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTime;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Uses ImapEngine library to fetch emails since last run.
 */
class ImapEngine_Imap_Email_Connection implements Email_Connection_Interface, Requires_Credentials, Supports_Fetching {

	use LoggerAwareTrait;

	/**
	 * The IMAP mailbox connection.
	 *
	 * @var Mailbox
	 */
	protected Mailbox $mailbox;

	/**
	 * The server settings the mailbox was built with, for describing connection failures.
	 *
	 * @var ?array{host: string, port: int, encryption: string}
	 */
	protected ?array $server_settings = null;

	/**
	 * Constructor.
	 *
	 * @param Email_Account_Settings_Interface $settings    The account being connected (passed to the config filter).
	 * @param LoggerInterface                  $logger      Logger.
	 * @param string                           $plugin_slug The consumer plugin's slug, so a filter can target one library instance.
	 */
	public function __construct(
		protected Email_Account_Settings_Interface $settings,
		LoggerInterface $logger,
		protected string $plugin_slug = '',
	) {
		$this->setLogger( $logger );
	}

	/**
	 * A short human-readable name for this connection type.
	 */
	public function get_friendly_name(): string {
		return 'IMAP';
	}

	/**
	 * IMAP does support querying the current read status of a message. (e.g. a webhook/AWS SNS delivery of email would not).
	 */
	public function can_read_status(): bool {
		return true;
	}

	/**
	 * IMAP can read/write the emails on the server.
	 * TODO: add a credentials-level `::can_mark_read()` to handle read-only accounts.
	 */
	public function can_mark_read(): bool {
		return true;
	}

	/**
	 * IMAP does support deleting messages.
	 */
	public function can_delete_on_server(): bool {
		return true;
	}

	/**
	 * Configure the mailbox connection. This is a pure setter — no network I/O happens here; the
	 * connection is established lazily on the first query, or eagerly via test_connection().
	 *
	 * Port is determined from the encryption value (993 for TLS; 143 for STARTTLS or none), or overridden
	 * by server:port.
	 *
	 * @param Account_Credentials_Interface|IMAP_Credentials_Interface $credentials The connection settings.
	 *
	 * @throws InvalidArgumentException When credentials are not IMAP credentials.
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void {

		if ( ! ( $credentials instanceof IMAP_Credentials_Interface ) ) {
			throw new InvalidArgumentException();
		}

		$server = $credentials->get_email_imap_server();
		$host   = $server;

		// Implicit TLS is on 993; a plain connection, upgraded with STARTTLS or not, is on 143.
		$encryption = match ( strtoupper( $credentials->get_encryption() ) ) {
			'' => '',
			'STARTTLS' => 'starttls',
			default => 'TLS',
		};
		$port = 'TLS' === $encryption ? 993 : 143;

		if ( str_contains( $server, ':' ) ) {
			[ $host, $port_str ] = explode( ':', $server, 2 );
			$port                = (int) $port_str;
		}

		/**
		 * The IMAP connection options.
		 *
		 * @see Mailbox::$config
		 */
		$config = array(
			'host'          => $host,
			'port'          => $port,
			'username'      => $credentials->get_email_account_username(),
			'password'      => $credentials->get_email_account_password(),
			'encryption'    => $encryption,
			'validate_cert' => $credentials->should_validate_cert(),
		);

		/**
		 * Filters the ImapEngine mailbox configuration before the connection is created.
		 *
		 * The keys are ImapEngine's (`host`, `port`, `username`, `password`, `encryption`, `validate_cert`);
		 * any other it supports may be added, e.g. `'debug' => true` to echo the IMAP conversation,
		 * `'debug' => '/path/to/imap.log'` to write it to a file, `'timeout' => 10`, `'proxy' => array( ... )`
		 * or `'authentication' => 'oauth'`. The password is present in the array.
		 *
		 * @see Mailbox::$config
		 *
		 * @param array<string,mixed>              $config      The configuration about to be passed to Mailbox::make().
		 * @param string                           $plugin_slug The consumer plugin's slug, to target one library instance.
		 * @param IMAP_Credentials_Interface       $credentials The account's credentials.
		 * @param Email_Account_Settings_Interface $account     The account being connected.
		 */
		$config = apply_filters( 'bh_wp_mailboxes_imap_mailbox_config', $config, $this->plugin_slug, $credentials, $this->settings );

		$this->mailbox = Mailbox::make( $config );

		$this->server_settings = array(
			'host'       => $host,
			'port'       => $port,
			'encryption' => $encryption,
		);
	}

	/**
	 * Connect the mailbox, rethrowing a connection failure with enough detail to act on.
	 *
	 * ImapEngine opens the socket with `@stream_socket_client()` and reports only the (often empty)
	 * `$errstr`, e.g. "Unable to connect to tls://host:993 ()". PHP still passes the suppressed warning
	 * to a user error handler, so one is installed for the duration of the connect to recover the real
	 * reason, which {@see self::describe_connection_failure()} appends along with the server settings
	 * used, the error code, and, when PHP gives no reason at all, a hint that the environment may not
	 * allow outbound connections.
	 *
	 * @throws ImapConnectionFailedException When the connection or login fails (same class as ImapEngine throws; the original is the previous exception).
	 */
	protected function connect(): void {
		$php_warning = null;

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporary, to recover the warning ImapEngine suppresses; restored in `finally`.
			function ( int $errno, string $errstr ) use ( &$php_warning ): bool {
				$php_warning = $errstr;
				return true;
			},
			E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
		);

		try {
			$this->mailbox->connect();
		} catch ( ImapConnectionFailedException $exception ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text (JSON → textContent, logger); escaping here would double-encode the quotes in PHP's warnings.
			throw new ImapConnectionFailedException(
				$this->describe_connection_failure( $exception, $php_warning ),
				$exception->getCode(),
				$exception
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * A user-facing description of a connection failure: ImapEngine's message, the server settings
	 * used, the PHP warning (if any) ImapEngine suppressed, the error code, and a hint when PHP gave
	 * no reason at all.
	 *
	 * @param ImapConnectionFailedException $exception   The failure ImapEngine threw.
	 * @param ?string                       $php_warning The warning PHP raised while connecting, if any.
	 */
	protected function describe_connection_failure( ImapConnectionFailedException $exception, ?string $php_warning ): string {
		$parts = array( rtrim( $exception->getMessage(), '.' ) . '.' );

		if ( ! is_null( $this->server_settings ) ) {
			$parts[] = sprintf(
				/* translators: 1: IMAP host, 2: port, 3: encryption, TLS or none */
				__( 'Server: %1$s:%2$d, encryption: %3$s.', 'bh-wp-mailboxes' ),
				$this->server_settings['host'],
				$this->server_settings['port'],
				'' === $this->server_settings['encryption'] ? __( 'none', 'bh-wp-mailboxes' ) : $this->server_settings['encryption']
			);
		}

		if ( ! is_null( $php_warning ) && '' !== trim( $php_warning ) ) {
			$parts[] = sprintf(
				/* translators: %s: the warning message PHP raised, e.g. "Connection refused" */
				__( 'PHP: %s.', 'bh-wp-mailboxes' ),
				rtrim( (string) preg_replace( '/^[\w\\\\]+\(\): /', '', trim( $php_warning ) ), '.' )
			);
		}

		if ( 0 !== $exception->getCode() ) {
			$parts[] = sprintf(
				/* translators: %d: the socket error number */
				__( 'Error code %d.', 'bh-wp-mailboxes' ),
				$exception->getCode()
			);
		}

		// "Unable to connect to tls://host:993 ()" with no warning: PHP could not even try. Explain why that happens.
		$no_reason_given = str_ends_with( trim( $exception->getMessage() ), '()' ) && ( is_null( $php_warning ) || '' === trim( $php_warning ) );
		if ( $no_reason_given ) {
			$wants_tls  = ! is_null( $this->server_settings ) && '' !== $this->server_settings['encryption'];
			$transports = stream_get_transports();
			if ( $wants_tls && ! in_array( 'tls', $transports, true ) && ! in_array( 'ssl', $transports, true ) ) {
				$parts[] = __( 'The "tls" stream transport is not available in this PHP (the openssl extension is missing), so encrypted connections cannot be made.', 'bh-wp-mailboxes' );
			} elseif ( self::is_php_wasm() ) {
				$parts[] = __( 'PHP could not open a network socket. This is WordPress Playground (PHP running as WebAssembly), which cannot make IMAP or other TCP connections when it runs in the browser; test the account on a normal WordPress install instead.', 'bh-wp-mailboxes' );
			} else {
				$parts[] = __( 'PHP could not open a network socket and gave no reason. This environment may not allow outbound connections (for example WordPress Playground running in the browser cannot connect to IMAP servers), or a firewall may be blocking the port.', 'bh-wp-mailboxes' );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Whether PHP is running as WebAssembly (WordPress Playground), which has no network sockets.
	 *
	 * Playground reports itself in `SERVER_SOFTWARE` ("PHP.wasm").
	 */
	public static function is_php_wasm(): bool {
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared, never output.

		return false !== stripos( $server_software, 'wasm' );
	}

	/**
	 * Connect to the IMAP server, surfacing authentication/connection failures.
	 *
	 * @return bool True when the connection and login succeed.
	 * @throws ImapConnectionFailedException When the IMAP connection or authentication fails; the message names the server settings used and PHP's reason.
	 */
	public function test_connection(): bool {
		$this->connect();
		return true;
	}

	/**
	 * Fetches emails from INBOX since the given time.
	 *
	 * Each message's UID, folder, and the folder's UIDVALIDITY are captured here (the parsed MIME
	 * `IMessage` cannot carry them) so read-status checks can later address the message by UID.
	 *
	 * @param DateTimeInterface $since_time The earliest date/time from which to fetch messages.
	 * @param int               $limit      Maximum number of messages to retrieve.
	 *
	 * @return Collection<int, Fetched_Email> Unsaved emails with their remote coordinates and read state.
	 */
	public function retrieve_emails( DateTimeInterface $since_time, int $limit = 100 ): Collection {

		// TODO: validate we have had credentials set.

		$this->connect();

		$inbox  = $this->mailbox->inbox();
		$folder = $inbox->path();
		$status = $inbox->status();
		// UIDVALIDITY is requested by ImapEngine's STATUS command; absent only on non-conforming servers.
		$uid_validity = isset( $status['UIDVALIDITY'] ) && is_numeric( $status['UIDVALIDITY'] ) ? (int) $status['UIDVALIDITY'] : null;

		// IMAP `SINCE` filters by date only — go back one extra day and filter by time in PHP.
		$previous_day = new DateTime()->setTimestamp( $since_time->getTimestamp() )->sub( new \DateInterval( 'P1D' ) );

		$this->logger->debug(
			'Fetching IMAP emails',
			array(
				'mailbox' => 'INBOX',
				'since'   => $previous_day->format( 'j-M-Y' ),
			)
		);

		// Call since() before chaining to avoid phpstan's @mixin ImapQueryBuilder type inference
		// resolving the chain to ImapQueryBuilder, which lacks limit().
		$message_query = $inbox->messages();
		$message_query->since( $previous_day );

		$messages = $message_query
			->limit( $limit )
			->withHeaders()
			->withBody()
			->withFlags()
			->get();

		$this->logger->debug( $messages->count() . ' found since ' . $previous_day->format( 'j-M-Y' ) );

		/**
		 * IMAP `SINCE` only filters by date, not time — filter by exact time here.
		 *
		 * @see https://stackoverflow.com/questions/32698415/php-imap-search-unseen-since-date-with-time
		 */
		$messages = $messages->filter(
			function ( MessageInterface $message ) use ( $since_time ) {
				$date = $message->date();
				return ! is_null( $date ) && $date->getTimestamp() >= $since_time->getTimestamp();
			}
		);

		/**
		 * Bundle the MessageInterface instance with metadata used later when deleting/marking-read individual emails.
		 *
		 * @var Collection<int, Fetched_Email> $fetched
		 */
		$fetched = new Collection(
			array_map(
				function ( MessageInterface $message ) use ( $folder, $uid_validity ): Fetched_Email {
					// Capture the UID and `\Seen` flag before parse() reduces the message to MIME.
					$uid            = $message->uid();
					$is_remote_read = $message->isSeen();
					$imessage       = $message->parse();

					$coordinates = new Remote_Email_Coordinates(
						message_id: $imessage->getMessageId() ?? '',
						remote_uid: (string) $uid,
						folder: $folder,
						uid_validity: $uid_validity,
					);

					return new Fetched_Email( $imessage, $coordinates, $is_remote_read );
				},
				$messages->values()->all()
			)
		);

		$this->logger->info(
			$fetched->count() . ' emails found in inbox since last run.',
			array( 'since' => $since_time )
		);

		return $fetched;
	}

	/**
	 * Determine whether the email is marked read (`\Seen`) on the server.
	 *
	 * Prefers a direct FETCH by the stored IMAP UID (fast, unambiguous), provided the stored
	 * UIDVALIDITY still matches the inbox — if the server reset UIDVALIDITY the stored UID points
	 * at a different message and must not be trusted. When there is no usable UID (Gmail-sourced
	 * coordinates, a folder move that voided the UID, or a UIDVALIDITY change) it falls back to a
	 * `HEADER "Message-ID"` search of the inbox.
	 *
	 * Both paths are scoped to INBOX; an email filed into another folder reports `false`.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 *
	 * @return bool True when the message is found and flagged `\Seen`; false when unread or not found.
	 */
	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool {

		$message = $this->find_message_by_uid( $coordinates )
			?? $this->find_message_by_message_id( $coordinates->message_id );

		if ( is_null( $message ) ) {
			$this->logger->warning(
				'Could not find email in inbox to read its remote read/unread status.',
				array(
					'message_id' => $coordinates->message_id,
					'remote_uid' => $coordinates->remote_uid,
				)
			);
			return false;
		}

		return $message->isSeen();
	}

	/**
	 * Mark the email read or unread on the server by setting/clearing its `\Seen` flag.
	 *
	 * Locates the message the same way as get_is_marked_read() — direct FETCH by the stored UID,
	 * falling back to a `HEADER "Message-ID"` search of the inbox.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 * @param bool                     $is_read     True to mark `\Seen`; false to clear it.
	 *
	 * @throws \Exception When the email cannot be found on the server.
	 */
	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void {

		$this->connect();

		$message = $this->find_message_by_uid( $coordinates )
			?? $this->find_message_by_message_id( $coordinates->message_id );

		if ( is_null( $message ) ) {
			$this->logger->warning(
				'Could not find email in inbox to change its remote read/unread status.',
				array(
					'message_id' => $coordinates->message_id,
					'remote_uid' => $coordinates->remote_uid,
				)
			);
			throw new \Exception( 'Could not find email in inbox to change its remote read/unread status.' );
		}

		if ( $is_read ) {
			$message->markSeen();
		} else {
			$message->unmarkSeen();
		}
	}

	/**
	 * Delete the email on the server by flagging it `\Deleted` and expunging it.
	 *
	 * Locates the message the same way as get_is_marked_read() — direct FETCH by the stored UID,
	 * falling back to a `HEADER "Message-ID"` search of the inbox.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 *
	 * @return bool True when the message was found and deleted.
	 * @throws \Exception When the email cannot be found on the server.
	 */
	public function do_delete_on_server( Remote_Email_Coordinates $coordinates ): bool {

		$message = $this->find_message_by_uid( $coordinates )
			?? $this->find_message_by_message_id( $coordinates->message_id );

		if ( is_null( $message ) ) {
			$this->logger->warning(
				'Could not find email in inbox to delete.',
				array(
					'message_id' => $coordinates->message_id,
					'remote_uid' => $coordinates->remote_uid,
				)
			);
			throw new \Exception( 'Could not find email in inbox to delete.' );
		}

		$message->markDeleted( expunge: true );

		return true;
	}

	/**
	 * Locate the message by its stored IMAP UID, but only when the stored UIDVALIDITY still matches
	 * the inbox — otherwise the UID is stale and could resolve to an unrelated message.
	 *
	 * @param Remote_Email_Coordinates $coordinates The stored coordinates.
	 *
	 * @return ?MessageInterface The message (with flags) or null when there is no usable/valid UID.
	 */
	private function find_message_by_uid( Remote_Email_Coordinates $coordinates ): ?MessageInterface {

		if ( ! is_numeric( $coordinates->remote_uid ) ) {
			return null;
		}

		$inbox = $this->mailbox->inbox();

		if ( ! is_null( $coordinates->uid_validity ) ) {
			$status         = $inbox->status();
			$current_uidval = isset( $status['UIDVALIDITY'] ) && is_numeric( $status['UIDVALIDITY'] ) ? (int) $status['UIDVALIDITY'] : null;
			if ( $current_uidval !== $coordinates->uid_validity ) {
				$this->logger->info(
					'Stored UIDVALIDITY no longer matches the inbox; falling back to Message-ID search.',
					array(
						'stored_uid_validity'  => $coordinates->uid_validity,
						'current_uid_validity' => $current_uidval,
					)
				);
				return null;
			}
		}

		$message_query = $inbox->messages();
		$message_query->withFlags();

		return $message_query->find( (int) $coordinates->remote_uid, ImapFetchIdentifier::Uid );
	}

	/**
	 * Locate the message by searching the inbox for its RFC822 `Message-ID` header.
	 *
	 * @param string $message_id The RFC822 Message-ID header value (with or without angle brackets).
	 *
	 * @return ?MessageInterface The message (with flags) or null when not found in the inbox.
	 */
	private function find_message_by_message_id( string $message_id ): ?MessageInterface {

		if ( '' === $message_id ) {
			return null;
		}

		// Call header() as its own statement so phpstan resolves the query as MessageQuery
		// (which has withFlags()/first()) rather than the @mixin ImapQueryBuilder.
		$message_query = $this->mailbox->inbox()->messages();
		$message_query->header( 'Message-ID', $message_id );

		return $message_query->withFlags()->first();
	}
}

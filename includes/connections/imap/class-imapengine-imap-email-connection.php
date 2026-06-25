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
	 * Constructor.
	 *
	 * @param Email_Account_Settings_Interface $settings TODO: unused.
	 * @param LoggerInterface                  $logger Logger.
	 */
	public function __construct(
		protected Email_Account_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
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
	 * Port is determined from the encryption value, or overridden by server:port.
	 *
	 * @param Account_Credentials_Interface|IMAP_Credentials_Interface $credentials The connection settings.
	 *
	 * @throws InvalidArgumentException When credentials are not IMAP credentials.
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void {

		if ( ! ( $credentials instanceof IMAP_Credentials_Interface ) ) {
			throw new InvalidArgumentException();
		}

		$server     = $credentials->get_email_imap_server();
		$host       = $server;
		$port       = $credentials->get_encryption() === '' ? 143 : 993;
		$encryption = $credentials->get_encryption() === '' ? '' : 'TLS';

		if ( str_contains( $server, ':' ) ) {
			[ $host, $port_str ] = explode( ':', $server, 2 );
			$port                = (int) $port_str;
		}

		/**
		 * Instantiate the mailbox with the IMAP connection options.
		 *
		 * @see Mailbox::$config
		 */
		$this->mailbox = Mailbox::make(
			array(
				'host'          => $host,
				'port'          => $port,
				'username'      => $credentials->get_email_account_username(),
				'password'      => $credentials->get_email_account_password(),
				'encryption'    => $encryption,
				'validate_cert' => false, // TODO: This was for my own use. Need a convention for controlling it.
			)
		);
	}

	/**
	 * Connect to the IMAP server, surfacing authentication/connection failures.
	 *
	 * @return bool True when the connection and login succeed.
	 * @throws ImapConnectionFailedException When the IMAP connection or authentication fails.
	 */
	public function test_connection(): bool {
		$this->mailbox->connect();
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

		$this->mailbox->connect();

		$inbox  = $this->mailbox->inbox();
		$folder = $inbox->path();
		$status = $inbox->status();
		// UIDVALIDITY is requested by ImapEngine's STATUS command; absent only on non-conforming servers.
		$uid_validity = isset( $status['UIDVALIDITY'] ) ? (int) $status['UIDVALIDITY'] : null;

		// IMAP `SINCE` filters by date only — go back one extra day and filter by time in PHP.
		$previous_day = ( new \DateTime() )->setTimestamp( $since_time->getTimestamp() )->sub( new \DateInterval( 'P1D' ) );

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

		$this->mailbox->connect();

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

		if ( is_null( $coordinates->remote_uid ) || ! is_numeric( $coordinates->remote_uid ) ) {
			return null;
		}

		$inbox = $this->mailbox->inbox();

		if ( ! is_null( $coordinates->uid_validity ) ) {
			$status         = $inbox->status();
			$current_uidval = isset( $status['UIDVALIDITY'] ) ? (int) $status['UIDVALIDITY'] : null;
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

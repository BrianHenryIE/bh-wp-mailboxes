<?php
/**
 * Connects to IMAP server and returns an array of emails.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3501
 * @see https://www.rfc-editor.org/info/rfc1176
 *
 * @package    brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\IMessage;

/**
 * Uses ImapEngine library to fetch emails since last run.
 */
class ImapEngine_Imap_Email_Fetcher implements Email_Fetcher_Interface {

	use LoggerAwareTrait;

	/**
	 * The IMAP mailbox connection.
	 */
	protected Mailbox $mailbox;

	/**
	 * Constructor.
	 *
	 * @param Email_Account_Settings_Interface $settings
	 * @param LoggerInterface                  $logger Logger.
	 */
	public function __construct(
		protected Email_Account_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
	}

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

	public function can_delete_on_server(): bool {
		return true;
	}


	/**
	 * @param Account_Credentials_Interface $credentials
	 *
	 * @throws \Exception When credentials are not IMAP credentials.
	 * @throws \Throwable When the IMAP connection fails.
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void {

		if ( ! ( $credentials instanceof IMAP_Credentials_Interface ) ) {
			return;
		}

		$server = $credentials->get_email_imap_server();
		$host   = $server;
		$port   = 143;
		$port   = 993;

		if ( str_contains( $server, ':' ) ) {
			[ $host, $port_str ] = explode( ':', $server, 2 );
			$port                = (int) $port_str;
		}

		$this->mailbox = Mailbox::make(
			array(
				'host'          => $host,
				// 'port'          => $port, // gets determined by encryption value.
				// 'port' => 993,
					'username'  => $credentials->get_email_account_username(),
				'password'      => $credentials->get_email_account_password(),
				// 'encryption'                           => $credentials->get_encryption(),
												'encryption' => 'tls',
				'validate_cert' => false,
			)
		);

		try {
			// Connect eagerly so authentication failures surface here in the constructor.
			$this->mailbox->connect();

		} catch ( \Throwable $t ) {

			throw $t;
		}
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

		/** @var Collection<int, Fetched_Email> $fetched */
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

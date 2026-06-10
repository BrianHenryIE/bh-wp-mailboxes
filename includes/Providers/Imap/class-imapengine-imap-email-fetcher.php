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

use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
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
	 *
	 * @var Mailbox
	 */
	protected Mailbox $mailbox;

	/**
	 * Constructor.
	 *
	 * @param Email_Account_Settings_Interface $settings Connection settings.
	 * @param LoggerInterface                  $logger Logger.
	 * @throws \Exception When credentials are not IMAP credentials.
	 * @throws \Throwable When the IMAP connection fails.
	 */
	public function __construct(
		protected Email_Account_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );

		if ( ! ( $settings->get_credentials() instanceof IMAP_Credentials_Interface ) ) {
			$this->logger->error( 'not IMAP credentials' );
			throw new \Exception();
		}

		/**
		 * The IMAP credentials cast from account credentials.
		 *
		 * @var IMAP_Credentials_Interface $credentials
		 */
		$credentials = $this->settings->get_credentials();

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
	 * @param DateTimeInterface $since_time The earliest date/time from which to fetch messages.
	 * @param int               $limit      Maximum number of messages to retrieve.
	 *
	 * @return Collection<IMessage> Unsaved, unparsed emails.
	 */
	public function retrieve_emails( DateTimeInterface $since_time, int $limit = 100 ): Collection {

		// `SINCE` filters by date only — go back one extra day and filter by time in PHP.
		$previous_day = ( clone $since_time )->sub( new \DateInterval( 'P1D' ) );

		$this->logger->debug(
			'Fetching IMAP emails',
			array(
				'mailbox' => 'INBOX',
				'since'   => $previous_day->format( 'j-M-Y' ),
			)
		);

		/**
		 * Raw ImapEngine messages from the server.
		 *
		 * @var Collection<Message> $messages
		 */
		$messages = $this->mailbox
			->inbox()
			->messages()
			->since( $previous_day )
			->limit( $limit )
			->withHeaders()
			->withBody()
			->get();

		$this->logger->debug( $messages->count() . ' found since ' . $previous_day->format( 'j-M-Y' ) );

		/**
		 * IMAP `SINCE` only filters by date, not time — filter by exact time here.
		 *
		 * @see https://stackoverflow.com/questions/32698415/php-imap-search-unseen-since-date-with-time
		 */
		$messages = $messages->filter(
			function ( Message $message ) use ( $since_time ) {
				$date = $message->date();
				return ! is_null( $date ) && $date->getTimestamp() >= $since_time->getTimestamp();
			}
		);

		/**
		 * Parsed IMessage objects.
		 *
		 * @var Collection<IMessage> $messages
		 */
		$messages = array_map(
			fn( Message $message ) => $message->parse(),
			$messages->all()
		);
		$messages = new Collection( $messages );

		$this->logger->info(
			$messages->count() . ' emails found in inbox since last run.',
			array( 'since' => $since_time )
		);

		return $messages;
	}
}

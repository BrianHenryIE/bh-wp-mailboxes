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
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Model\ZImessage_Collection;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Mailbox;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Uses ImapEngine library to fetch emails since last run.
 */
class ImapEngine_Imap_Email_Fetcher implements Email_Fetcher_Interface {

	use LoggerAwareTrait;

	protected Mailbox $mailbox;

	/**
	 * @param string                     $cpt_name Unused — kept for interface compatibility with callers.
	 * @param Mailbox_Settings_Interface $settings Connection settings.
	 * @param LoggerInterface            $logger Logger.
	 */
	public function __construct(
		protected Mailbox_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );

		if ( ! ( $settings->get_credentials() instanceof IMAP_Credentials_Interface ) ) {
			$this->logger->error( 'not IMAP credentials' );
			throw new \Exception();
		}

		/** @var IMAP_Credentials_Interface $credentials */
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

			$this->dumpExceptionProperties( $t, $this->logger );

			throw $t;
		}
	}

	function dumpExceptionProperties( \Throwable $e, LoggerInterface $logger ): void {
		$ref = new \ReflectionClass( $e );

		echo 'Exception class: ' . $ref->getName() . PHP_EOL;

		foreach ( $ref->getProperties() as $prop ) {
			$name  = $prop->getName();
			$value = $prop->getValue( $e );

			$logger->error( "$name: " . print_r( $value, true ) );
		}
	}

		/**
		 * Fetches emails from INBOX since the given time.
		 *
		 * @param DateTimeInterface $since_time
		 *
		 * @return ZImessage_Collection Unsaved emails as parsed MIME messages.
		 */
	public function retrieve_emails( DateTimeInterface $since_time, int $limit = 100 ): ZImessage_Collection {

		// `SINCE` filters by date only — go back one extra day and filter by time in PHP.
		$previous_day = ( clone $since_time )->sub( new \DateInterval( 'P1D' ) );

		$this->logger->debug(
			'Fetching IMAP emails',
			array(
				'mailbox' => 'INBOX',
				'since'   => $previous_day->format( 'j-M-Y' ),
			)
		);

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
		 * IMAP SINCE only filters by date, not time — filter by exact time here.
		 *
		 * @see https://stackoverflow.com/questions/32698415/php-imap-search-unseen-since-date-with-time
		 */
		$new_emails = new ZImessage_Collection();
		foreach ( $messages as $message ) {
			$date = $message->date();
			if ( $date !== null && $date->getTimestamp() < $since_time->getTimestamp() ) {
				continue;
			}
			$new_emails->add( $message->parse() );
		}

		$this->logger->debug(
			$new_emails->count() . ' emails found in inbox since last run.',
			array( 'since' => $since_time )
		);

		return $new_emails;
	}
}

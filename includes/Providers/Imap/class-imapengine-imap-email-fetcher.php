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
use BrianHenryIE\WP_Mailboxes\BH_Email;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use DateTime;
use DateTimeInterface;
use ImapEngine\Imap\Exception\InvalidDateHeaderException;
use ImapEngine\Imap\Search\Date\Since;
use ImapEngine\Imap\Server;
use ImapEngine\Imap\ServerInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Uses ImapEngine library to fetch emails since last run.
 */
class ImapEngine_Imap_Email_Fetcher implements Email_Fetcher_Interface {

	use LoggerAwareTrait;

	protected string $cpt;

	/**
	 * Server settings: DNS/IP, username, password, filters.
	 */
	protected Mailbox_Settings_Interface $settings;

	/**
	 * server instances.
	 *
	 * @var Server_Container_Interface
	 */
	protected Server_Container_Interface $new_server;

	protected $imap_server;

	protected $connection;

	protected int $mailbox_category_term_id;

	/**
	 * Email_Fetcher constructor.
	 *
	 * @param string                     $cpt_name
	 * @param Mailbox_Settings_Interface $settings Connection settings and filters.
	 * @param LoggerInterface            $logger Logger.
	 */
	public function __construct( string $cpt_name, Mailbox_Settings_Interface $settings, LoggerInterface $logger ) {
		$this->setLogger( $logger );
		$this->settings = $settings;
		$this->cpt      = $cpt_name;

		if ( ! ( $settings->get_credentials() instanceof IMAP_Credentials_Interface ) ) {
			$this->logger->error( 'not IMAP credentials' );
			throw new \Exception();
		}

		$this->new_server = new class() implements Server_Container_Interface {

			/**
			 * Returns a new ImapEngine\Imap\Server.
			 *
			 * @param string $url_or_ip The IMAP server address, with optional :port.
			 * @return ServerInterface
			 */
			public function get_server( string $url_or_ip ): ServerInterface {
				$port = '';

				if ( false !== strpos( ':', $url_or_ip ) ) {
					$parts     = explode( ':', $url_or_ip );
					$url_or_ip = $parts[0];
					$port      = $parts[1];
				}

				// TODO: Add option in settings.
				$flags = '/imap/ssl/novalidate-cert';

				return new Server( $url_or_ip, $port, $flags );
			}
		};

		/** @var IMAP_Credentials_Interface $server_settings */
		$server_settings = $this->settings->get_credentials();

		$server_url_or_ip = $server_settings->get_email_imap_server();
		$username         = $server_settings->get_email_account_username();
		$password         = $server_settings->get_email_account_password();

		$this->imap_server = $this->new_server->get_server( $server_url_or_ip );

		// \ImapEngine\Imap\Exception\AuthenticationFailedException
		$this->connection = $this->imap_server->authenticate( $username, $password );

		$account_category_slug = sanitize_title( $this->settings->get_account_unique_friendly_name() );
		$mailbox_category      = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		if ( ! ( $mailbox_category instanceof \WP_Term ) ) {
			$this->logger->error( 'Mailbox category not found. fetch emails probably run before post types registered' );
			throw new \Exception();
		}

		$this->mailbox_category_term_id = $mailbox_category->term_id;
	}

	/**
	 * Connects to the IMAP server and returns the Email objects.
	 *
	 * @param DateTime $since_time Time to fetch emails since, i.e. when the reconciliation was last run. (NOT the date of the last email, which could be older than the delete frequency).
	 *
	 * @return array<string, BH_Email> where string is MessageInterface::getId() (BasicMessageInterface::getId()).
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): array {

		$mailbox_name = 'INBOX';

		$mailbox = $this->connection->getMailbox( $mailbox_name );

		// `Since` converts the datetime into a day.
		// Since discards the timezone. Servers use their own timezone for IMAP. Safe option is the fetch back a little
		// further. This code may not be necessary.
		$previous_day     = ( clone $since_time )->sub( new \DateInterval( 'P1D' ) );
		$search_condition = new Since( $previous_day );

		$this->logger->debug(
			'Fetching IMAP emails',
			array(
				'mailbox'          => $mailbox_name,
				'search_condition' => $search_condition,
				'since'            => $previous_day->format( 'j-M-Y' ),
			)
		);

		/** @var \ImapEngine\Imap\MessageIterator $emails */
		$emails = $mailbox->getMessages( $search_condition );

		$this->logger->debug( count( $emails ) . ' found since ' . $previous_day->format( 'j-M-Y' ) );

		/**
		 * We cannot search IMAP with a time, only a date, so we must filter by time afterwards.
		 *
		 * @see https://stackoverflow.com/questions/32698415/php-imap-search-unseen-since-date-with-time
		 */
		$new_imapengine_emails = array();
		foreach ( $emails as $imapengine_email ) {
			try {
				$email_datetime = $imapengine_email->getDate();
			} catch ( InvalidDateHeaderException $exception ) {
				// If the date is invalid... let's save it anyway.
				// We may have to deal with this exception again later.
				$new_imapengine_emails[] = $imapengine_email;
				continue;
			}
			if ( $email_datetime < $since_time ) {
				continue;
			}
			$new_imapengine_emails[] = $imapengine_email;
		}

		$this->logger->debug(
			count( $new_imapengine_emails ) . ' emails found in inbox since last run.',
			array(
				'new_email_count' => count( $new_imapengine_emails ),
				'since'           => $since_time,
			)
		);

		$new_emails = array();
		foreach ( $new_imapengine_emails as $imapengine_email ) {
			$new_emails[ $imapengine_email->getId() ] = new ImapEngine_BH_Email( $imapengine_email, $this->cpt, $this->mailbox_category_term_id );
		}

		return $new_emails;
	}
}

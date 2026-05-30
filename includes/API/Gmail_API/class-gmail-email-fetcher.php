<?php
/**
 * Fetch emails from Gmail using Google PHP SDK.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Model\ZImessage_Collection;
use DateTime;
use DateTimeInterface;
use Exception;
use Google\Service\Gmail\ListMessagesResponse;
use Google_Client;
use Google_Service_Gmail;
use ZBateson\MailMimeParser\MailMimeParser;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Gmail_Email_Fetcher implements Email_Fetcher_Interface {
	use LoggerAwareTrait;

	protected string $cpt;

	/**
	 *
	 */
	protected Mailbox_Settings_Interface $settings;

	/**
	 * Email_Fetcher constructor.
	 *
	 * @param string                     $cpt
	 * @param Mailbox_Settings_Interface $settings Connection settings and filters.
	 * @param LoggerInterface            $logger Logger.
	 */
	public function __construct( string $cpt, Mailbox_Settings_Interface $settings, LoggerInterface $logger ) {
		$this->cpt      = $cpt;
		$this->logger   = $logger;
		$this->settings = $settings;
	}


	/**
	 * Returns an authorized API client.
	 *
	 * @uses \BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface::get_credentials()
	 *
	 * @see https://developers.google.com/gmail/api/quickstart/php
	 *
	 * @return ?Google_Client the authorized client object.
	 */
	public function getClient(): ?Google_Client {

		/** @var Google_API_Credentials_Interface $saved_credentials */
		$saved_credentials = $this->settings->get_credentials();

		if ( is_null( $saved_credentials->get_access_token() ) ) {
			return null;
		}

		$client = new Google_Client();
		$client->setLogger( $this->logger );

		// TODO:
		$client->setApplicationName( 'Gmail API PHP Quickstart' );
		$client->setScopes( Google_Service_Gmail::GMAIL_READONLY );

		$client->setAuthConfig( $saved_credentials->get_project_credentials() );

		$client->setAccessType( 'offline' );
		$client->setPrompt( 'select_account consent' );

		// Load previously authorized token from a file, if it exists.
		// The file token.json stores the user's access and refresh tokens, and is
		// created automatically when the authorization flow completes for the first
		// time.
		// $tokenPath = __DIR__ . '/token.json';

		$access_token = $saved_credentials->get_access_token();
		$client->setAccessToken( $access_token );

		// If there is no previous token or it's expired.
		if ( $client->isAccessTokenExpired() ) {

			// throw new \Exception( 'Access token is expired' );

			// Refresh the token if possible, else fetch a new one.
			if ( $client->getRefreshToken() ) {
				$client->fetchAccessTokenWithRefreshToken( $client->getRefreshToken() );
			} else {
				// Request authorization from the user.
				$authUrl = $client->createAuthUrl();
				printf( "Open the following link in your browser:\n%s\n", $authUrl );
				print 'Enter verification code: ';
				$authCode = trim( fgets( STDIN ) );

				// Exchange authorization code for an access token.
				$access_token = $client->fetchAccessTokenWithAuthCode( $authCode );
				$client->setAccessToken( $access_token );

				// Check to see if there was an error.
				if ( array_key_exists( 'error', $access_token ) ) {
					throw new Exception( join( ', ', $access_token ) );
				}
			}

			$token_path = __DIR__ . '/token.json';
			// Save the token to a file.
			if ( ! file_exists( dirname( $token_path ) ) ) {
				mkdir( dirname( $token_path ), 0700, true );
			}
			file_put_contents( $token_path, json_encode( $client->getAccessToken() ) );
		}
		return $client;
	}

	/**
	 * TODO: Fetch from oldest to newest.
	 *
	 * @param DateTime $since_time
	 *
	 * @return ZImessage_Collection
	 * @throws Exception
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): ZImessage_Collection {

		$emails = new ZImessage_Collection();

		/** @var Google_Client $client */
		$client  = $this->getClient();
		$service = new Google_Service_Gmail( $client );

		$opts = array(
			// 'includeSpamTrash' => // bool
			// 'labelIds' =>         // string
			// 'maxResults' =>       // string
			// 'pageToken' =>        // string
			'q' => 'after:' . $since_time->getTimestamp(),
		);
		/** @var ListMessagesResponse $r */
		$r = $service->users_messages->listUsersMessages( 'me', $opts );

		$parser   = new MailMimeParser();
		$messages = $r->getMessages();
		foreach ( $messages as $message ) {

			// Request format=raw to receive the full RFC 2822 message (base64url-encoded).
			$single_message = $service->users_messages->get( 'me', $message->id, array( 'format' => 'raw' ) );

			$raw = $single_message->getRaw();
			if ( empty( $raw ) ) {
				continue;
			}

			$rfc2822 = base64_decode( strtr( $raw, '-_', '+/' ) );
			$emails->add( $parser->parse( $rfc2822, false ) );
		}

		return $emails;
	}

	/**
	 *
	 * @see https://stackoverflow.com/questions/44218353/decode-email-body-in-php-gmail-api
	 *
	 * @param $data
	 *
	 * @return false|string
	 */
	protected function gmail_body_decode( string $data ): string {
		// @see https://php.net/manual/es/function.base64-decode.php#118244
		$data = base64_decode( str_replace( array( '-', '_' ), array( '+', '/' ), $data ) );

		// Stack Overflow says can also use `quoted_printable_decode()`.
		$data = imap_qprint( $data );

		return $data;
	}
}

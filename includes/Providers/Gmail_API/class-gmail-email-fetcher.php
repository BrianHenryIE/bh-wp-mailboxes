<?php
/**
 * Fetch emails from Gmail using Google PHP SDK.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTime;
use DateTimeInterface;
use Exception;
use Google\Service\Gmail\ListMessagesResponse;
use Google_Client;
use Google_Service_Gmail;
use Illuminate\Support\Collection;
use ZBateson\MailMimeParser\MailMimeParser;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Fetches emails from Gmail using the Google PHP SDK.
 */
class Gmail_Email_Fetcher implements Email_Fetcher_Interface {
	use LoggerAwareTrait;

	/**
	 * Email_Fetcher constructor.
	 *
	 * @param Email_Account_Settings_Interface $settings Connection settings and filters.
	 * @param LoggerInterface                  $logger Logger.
	 */
	public function __construct(
		protected Email_Account_Settings_Interface $settings,
		protected Google_API_Credentials_Interface $credentials,
		LoggerInterface $logger
	) {
		$this->logger = $logger;
	}


	/**
	 * Returns an authorized API client.
	 *
	 * @return ?Google_Client the authorized client object.
	 * @see https://developers.google.com/gmail/api/quickstart/php
	 * @throws Exception When authorization fails.
	 *
	 * @uses Email_Account_Settings_Interface::get_credentials
	 */
	public function getClient(): ?Google_Client {

		/**
		 * The saved Google API credentials.
		 *
		 * @var Google_API_Credentials_Interface $saved_credentials
		 */
		$saved_credentials = $this->settings->get_credentials();

		if ( is_null( $saved_credentials->get_access_token() ) ) {
			return null;
		}

		$client = new Google_Client();
		$client->setLogger( $this->logger );

		// TODO: Replace with a configurable application name.
		$client->setApplicationName( 'Gmail API PHP Quickstart' );
		$client->setScopes( Google_Service_Gmail::GMAIL_READONLY );

		$client->setAuthConfig( (array) $saved_credentials->get_project_credentials() );

		$client->setAccessType( 'offline' );
		$client->setPrompt( 'select_account consent' );

		// Load previously authorized token from a file, if it exists.
		// The file token.json stores the user's access and refresh tokens, and is
		// created automatically when the authorization flow completes for the first time.

		$access_token = $saved_credentials->get_access_token();
		$client->setAccessToken( (array) $access_token );

		// If there is no previous token or it's expired.
		if ( $client->isAccessTokenExpired() ) {

			// Refresh the token if possible, else fetch a new one.
			if ( $client->getRefreshToken() ) {
				$client->fetchAccessTokenWithRefreshToken( $client->getRefreshToken() );
			} else {
				// Request authorization from the user.
				$auth_url = $client->createAuthUrl();
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not HTML.
				printf( "Open the following link in your browser:\n%s\n", $auth_url );
				print 'Enter verification code: ';
				$auth_code = trim( (string) fgets( STDIN ) );

				// Exchange authorization code for an access token.
				$access_token = $client->fetchAccessTokenWithAuthCode( $auth_code );
				$client->setAccessToken( $access_token );

				// Check to see if there was an error.
				if ( array_key_exists( 'error', $access_token ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not direct output.
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
	 * @param DateTimeInterface $since_time The earliest date/time from which to retrieve emails.
	 *
	 * @throws Exception When the Gmail API call fails.
	 *
	 * @return Collection<int, \ZBateson\MailMimeParser\IMessage>
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): Collection {

		$emails = new Collection();

		/**
		 * The authorized Google API client.
		 *
		 * @var Google_Client $client
		 */
		$client  = $this->getClient();
		$service = new Google_Service_Gmail( $client );

		$opts = array(
			// 'includeSpamTrash' => // bool
			// 'labelIds' =>         // string
			// 'maxResults' =>       // string
			// 'pageToken' =>        // string
			'q' => 'after:' . $since_time->getTimestamp(),
		);
		/**
		 * The list of Gmail messages matching the query.
		 *
		 * @var ListMessagesResponse $r
		 */
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
	 * Decodes a Gmail API base64url-encoded message body part.
	 *
	 * @param string $data Base64url-encoded email body data.
	 *
	 * @see https://stackoverflow.com/questions/44218353/decode-email-body-in-php-gmail-api
	 */
	protected function gmail_body_decode( string $data ): string {
		// @see https://php.net/manual/es/function.base64-decode.php#118244
		$decoded = base64_decode( str_replace( array( '-', '_' ), array( '+', '/' ), $data ) );

		// Stack Overflow says can also use `quoted_printable_decode()`.
		$decoded = imap_qprint( $decoded );
		if ( false === $decoded ) {
			return '';
		}

		return $decoded;
	}
}

<?php
/**
 * Fetch emails from Gmail using Google PHP SDK.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use DateTime;
use DateTimeInterface;
use Exception;
use Google\Service\Gmail\ListMessagesResponse;
use Google_Client;
use Google_Service_Gmail;
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

		$client->setAuthConfig( $saved_credentials->get_project_credentials());

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
	 * @return BH_Email[]
	 * @throws Exception
	 */
	public function retrieve_emails( DateTimeInterface $since_time ): array {

		$emails = array();

		/** @var Google_Client $client */
		$client  = $this->getClient();
		$service = new Google_Service_Gmail( $client );

		$user = 'me';

		$opts = array(
			// 'includeSpamTrash' => // bool  Include messages from `SPAM` and `TRASH` in the results.
			// 'labelIds' =>         // string  Only return messages with labels that match all of the specified label IDs.
			// 'maxResults' =>       // string  Maximum number of messages to return. This field defaults to 100. The maximum allowed value for this field is 500.
			// 'pageToken' =>        // string  Page token to retrieve a specific page of results in the list.
			'q' => 'after:' . $since_time->getTimestamp(),               // string Only return messages matching the specified query. Supports the same query format as the Gmail search box. For example, `"from:someuser@example.com rfc822msgid: is:unread"`. Parameter cannot be used when accessing the api using the gmail.metadata scope.
		);
		// "Invalid grant bad request"
		/** @var ListMessagesResponse $r */
		$r = $service->users_messages->listUsersMessages( $user, $opts );

		$account_category_slug = sanitize_title( $this->settings->get_account_unique_friendly_name() );
		$mailbox_category      = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		if ( false === $mailbox_category ) {
			$this->logger->error( 'Mailbox category not found. fetch emails probably run before post types registered' );
		}

		$messages = $r->getMessages();
		foreach ( $messages as $message ) {

			$single_message = $service->users_messages->get( 'me', $message->id ); // , $optParamsGet2);

			$raw                = $single_message->getRaw();
			$message_id         = $single_message->getId();
			$message_history_id = $single_message->getHistoryId();
			$thread_id          = $single_message->getThreadId();
			$internal_date      = $single_message->getInternalDate();
			$label_ids          = $single_message->getLabelIds();

			$message_payload = $single_message->getPayload();

			$new_email = array();

			$gmail_message_headers = $message_payload->getHeaders();

			$headers = array();
			foreach ( $gmail_message_headers as $gmail_message_header ) {
				$headers[ $gmail_message_header->getName() ] = $gmail_message_header->getValue();
			}

			$new_email['cpt'] = $this->cpt;

			$new_email['account_category_id'] = $mailbox_category->term_id;

			$new_email['headers']   = $headers;
			$new_email['subject']   = $headers['Subject'];
			$new_email['from']      = $headers['From'];
			$new_email['meta_data'] = array();

			$message_body = $message_payload->getBody()->getData();

			$b64_plain = '';
			$b64_html  = '';

			// If this is null,.... is this always null?
			if ( is_null( $message_body ) ) {

				foreach ( $message_payload->getParts() as $part ) {

					switch ( $part->getMimeType() ) {
						case 'text/plain':
							$b64_plain .= $part->getBody()->getData();
							break;
						case 'text/html':
							$b64_html .= $part->getBody()->getData();
							break;
						default:
							$mime = $part->getMimeType();
							break;
					}
				}
			}

			$plain = $this->gmail_body_decode( $b64_plain );
			$html  = $this->gmail_body_decode( $b64_html );

			$new_email['body_text'] = $plain;// $message_body_text;
			$new_email['body_html'] = $html;// $message_body_html;

			$bh_email = new Gmail_BH_Email( $new_email );

			$emails[] = $bh_email;
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

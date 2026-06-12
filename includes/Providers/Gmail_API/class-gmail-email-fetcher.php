<?php
/**
 * Fetch emails from Gmail using Google PHP SDK.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTimeInterface;
use Exception;
use Google\Service\Gmail\ListMessagesResponse;
use Google\Service\Gmail\Message as Gmail_Message;
use Google\Service\Gmail\ModifyMessageRequest;
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

	protected Google_API_Credentials_Interface $credentials;

	/**
	 * Email_Fetcher constructor.
	 *
	 * @param Email_Account_Settings_Interface $settings Connection settings and filters.
	 * @param LoggerInterface                  $logger Logger.
	 */
	public function __construct(
		protected Email_Account_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->logger = $logger;
	}

	public function set_credentials( Account_Credentials_Interface $credentials ): void {
		if ( ! ( $credentials instanceof Google_API_Credentials_Interface ) ) {
			throw new Exception( 'Credentials must implement Google_API_Credentials_Interface' );
		}
		$this->credentials = $credentials;
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
		$saved_credentials = $this->credentials;

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
	 * @return Collection<int, Fetched_Email>
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

			// Request format=raw to receive the full RFC 2822 message (base64url-encoded);
			// the response also carries labelIds, from which the read state is derived.
			$single_message = $service->users_messages->get( 'me', $message->id, array( 'format' => 'raw' ) );

			$raw = $single_message->getRaw();
			if ( empty( $raw ) ) {
				continue;
			}

			$rfc2822  = base64_decode( strtr( $raw, '-_', '+/' ) );
			$imessage = $parser->parse( $rfc2822, false );

			// The Gmail message id is a stable, label-move-resilient handle; store it as the remote uid.
			$coordinates = new Remote_Email_Coordinates(
				message_id: $imessage->getMessageId() ?? '',
				remote_uid: (string) $message->id,
			);

			$emails->add(
				new Fetched_Email( $imessage, $coordinates, $this->is_read_from_labels( $single_message->getLabelIds() ) )
			);
		}

		return $emails;
	}

	/**
	 * A Gmail message is unread while it carries the `UNREAD` label.
	 *
	 * @param string[]|null $label_ids The message's Gmail label ids.
	 */
	protected function is_read_from_labels( ?array $label_ids ): bool {
		return ! in_array( 'UNREAD', (array) $label_ids, true );
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

	/**
	 * Determine whether the email is marked read on the server.
	 *
	 * Prefers a direct lookup by the stored Gmail message id (which survives label/folder moves);
	 * falls back to a `rfc822msgid:` search on the RFC822 Message-ID when the id is absent or the
	 * message can no longer be fetched by it.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 *
	 * @return bool True when the message is found and read; false when unread or not found.
	 */
	public function get_is_marked_read( Remote_Email_Coordinates $coordinates ): bool {

		/**
		 * The authorized Google API client.
		 *
		 * @var Google_Client $client
		 */
		$client  = $this->getClient();
		$service = new Google_Service_Gmail( $client );

		$message = $this->get_message_by_remote_uid( $service, $coordinates->remote_uid )
			?? $this->get_message_by_rfc822_id( $service, $coordinates->message_id );

		if ( is_null( $message ) ) {
			$this->logger->warning(
				'Could not find email on the server to read its remote read/unread status.',
				array(
					'message_id' => $coordinates->message_id,
					'remote_uid' => $coordinates->remote_uid,
				)
			);
			return false;
		}

		return $this->is_read_from_labels( $message->getLabelIds() );
	}

	/**
	 * Mark the email read or unread on the server by toggling its `UNREAD` label.
	 *
	 * Locates the message the same way as get_is_marked_read() — by the stored Gmail message id,
	 * falling back to a `rfc822msgid:` search.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 * @param bool                     $is_read     True to remove `UNREAD`; false to add it.
	 *
	 * @throws \Exception When the email cannot be found on the server.
	 */
	public function set_is_marked_read( Remote_Email_Coordinates $coordinates, bool $is_read = true ): void {

		/**
		 * The authorized Google API client.
		 *
		 * @var Google_Client $client
		 */
		$client  = $this->getClient();
		$service = new Google_Service_Gmail( $client );

		$message = $this->get_message_by_remote_uid( $service, $coordinates->remote_uid )
			?? $this->get_message_by_rfc822_id( $service, $coordinates->message_id );

		if ( is_null( $message ) ) {
			$this->logger->warning(
				'Could not find email on the server to change its remote read/unread status.',
				array(
					'message_id' => $coordinates->message_id,
					'remote_uid' => $coordinates->remote_uid,
				)
			);
			throw new \Exception( 'Could not find email on the server to change its remote read/unread status.' );
		}

		$request = new ModifyMessageRequest();
		if ( $is_read ) {
			$request->setRemoveLabelIds( array( 'UNREAD' ) );
		} else {
			$request->setAddLabelIds( array( 'UNREAD' ) );
		}

		$service->users_messages->modify( 'me', $message->getId(), $request );
	}

	/**
	 * Fetch a message's metadata (labels) directly by its Gmail message id.
	 *
	 * @param Google_Service_Gmail $service    The authorized Gmail service.
	 * @param ?string              $remote_uid The stored Gmail message id, if any.
	 *
	 * @return ?Gmail_Message The message metadata, or null when absent/not retrievable.
	 */
	protected function get_message_by_remote_uid( Google_Service_Gmail $service, ?string $remote_uid ): ?Gmail_Message {
		if ( is_null( $remote_uid ) || '' === $remote_uid ) {
			return null;
		}
		try {
			return $service->users_messages->get( 'me', $remote_uid, array( 'format' => 'metadata' ) );
		} catch ( \Throwable $t ) {
			// e.g. 404 when the message was deleted; fall back to a Message-ID search.
			return null;
		}
	}

	/**
	 * Locate a message by its RFC822 Message-ID via Gmail's `rfc822msgid:` search operator.
	 *
	 * @param Google_Service_Gmail $service    The authorized Gmail service.
	 * @param string               $message_id The RFC822 Message-ID header value.
	 *
	 * @return ?Gmail_Message The message metadata, or null when not found.
	 */
	protected function get_message_by_rfc822_id( Google_Service_Gmail $service, string $message_id ): ?Gmail_Message {
		if ( '' === $message_id ) {
			return null;
		}

		/**
		 * The list of Gmail messages matching the search.
		 *
		 * @var ListMessagesResponse $r
		 */
		$r        = $service->users_messages->listUsersMessages( 'me', array( 'q' => 'rfc822msgid:' . $message_id ) );
		$messages = $r->getMessages();

		if ( empty( $messages ) ) {
			return null;
		}

		return $this->get_message_by_remote_uid( $service, $messages[0]->id );
	}

	public function can_mark_read(): bool {
		return true;
	}

	public function can_delete_on_server(): bool {
		return true;
	}

	public function can_read_status(): bool {
		return true;
	}
}

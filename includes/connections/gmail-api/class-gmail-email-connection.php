<?php
/**
 * Fetch emails from Gmail using Google PHP SDK.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Requires_Credentials;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use DateTimeInterface;
use Exception;
use Google\Service\Gmail\ListMessagesResponse;
use Google\Service\Gmail\Message as Gmail_Message;
use Google\Service\Gmail\ModifyMessageRequest;
use Google_Client;
use Google_Service_Gmail;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ZBateson\MailMimeParser\MailMimeParser;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Fetches emails from Gmail using the Google PHP SDK.
 */
class Gmail_Email_Connection implements Email_Connection_Interface, Requires_Credentials, Supports_Fetching {
	use LoggerAwareTrait;

	/**
	 * The Google Developer Console project credentials, and the access token.
	 *
	 * @var Google_API_Credentials_Interface The project and access token json.
	 */
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

	/**
	 * Configure the instance with the credentials to connect with.
	 *
	 * @param Account_Credentials_Interface|Google_API_Credentials_Interface $credentials OAuth project credentials + access token.
	 * @throws InvalidArgumentException If incorrect credentials type provided.
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void {
		if ( ! ( $credentials instanceof Google_API_Credentials_Interface ) ) {
			throw new InvalidArgumentException( 'Credentials must implement Google_API_Credentials_Interface' );
		}
		$this->credentials = $credentials;
	}

	/**
	 * Verify the credentials authenticate by making a cheap authorized API call (the user profile).
	 *
	 * @return bool True when the call succeeds.
	 * @throws \Throwable When authorization or the API call fails.
	 */
	public function test_connection(): bool {
		$this->get_gmail_service()->users->getProfile( 'me' );
		return true;
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

		$client = $this->make_client();
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
				printf( "Open the following link in your browser:\n%s\n", esc_html( $auth_url ) );
				print 'Enter verification code: ';
				$auth_code = trim( (string) fgets( STDIN ) );

				// Exchange authorization code for an access token.
				$access_token = $client->fetchAccessTokenWithAuthCode( $auth_code );
				$client->setAccessToken( $access_token );

				// Check to see if there was an error.
				if ( array_key_exists( 'error', $access_token ) ) {
					throw new Exception( join( ', ', array_map( 'esc_html', $access_token ) ) );
				}
			}

			$this->save_access_token( __DIR__ . '/token.json', (string) wp_json_encode( $client->getAccessToken() ) );
		}
		return $client;
	}

	/**
	 * Use the stored refresh token to obtain a fresh access token, without saving it anywhere.
	 *
	 * Unlike {@see getClient()} — which refreshes as a side effect and writes the new token to disk —
	 * this returns the new {@see Access_Token} for the caller to do with as they please (e.g. print it,
	 * fire an action). Google omits the refresh token from a refresh response, so the original is
	 * carried over to keep the returned value object complete.
	 *
	 * @return Access_Token The freshly minted access token.
	 * @throws Exception When no credentials/refresh token are set, or the refresh request fails.
	 */
	public function refresh_access_token(): Access_Token {

		if ( ! isset( $this->credentials ) ) {
			throw new Exception( 'No credentials set on the Gmail provider.' );
		}

		$existing_token = $this->credentials->get_access_token();

		if ( is_null( $existing_token ) || '' === $existing_token->refresh_token ) {
			throw new Exception( 'No refresh token available to refresh the Gmail access token.' );
		}

		$client = $this->make_oauth_client();

		/**
		 * The Google token endpoint response.
		 *
		 * @var array<string,mixed> $new_token
		 */
		$new_token = $client->fetchAccessTokenWithRefreshToken( $existing_token->refresh_token );

		if ( isset( $new_token['error'] ) ) {
			throw new Exception(
				'Failed to refresh Gmail access token: ' . esc_html( implode( ', ', array_map( 'strval', $new_token ) ) )
			);
		}

		// Google omits fields that are unchanged from the original token (notably the refresh token);
		// carry them over so the returned value object is complete.
		$data = (object) array(
			'access_token'  => $new_token['access_token'],
			'expires_in'    => $new_token['expires_in'] ?? $existing_token->expires_in,
			'scope'         => $new_token['scope'] ?? $existing_token->scope,
			'token_type'    => $new_token['token_type'] ?? $existing_token->token_type,
			'created'       => $new_token['created'] ?? time(),
			'refresh_token' => $new_token['refresh_token'] ?? $existing_token->refresh_token,
		);

		return Access_Token::from_json( $data );
	}

	/**
	 * The Google consent-screen URL a user visits to authorize this application for the first time.
	 *
	 * @return string The authorization URL.
	 * @throws Exception When no credentials are set.
	 */
	public function get_authorization_url(): string {
		return $this->make_oauth_client()->createAuthUrl();
	}

	/**
	 * Exchange a first-time authorization code for an access token, without saving it anywhere.
	 *
	 * @param string $auth_code The verification code from the consent screen.
	 *
	 * @return Access_Token The newly minted access token (including the long-lived refresh token).
	 * @throws Exception When no credentials are set, the exchange fails, or no refresh token is returned.
	 */
	public function fetch_access_token_with_auth_code( string $auth_code ): Access_Token {

		$client = $this->make_oauth_client();

		/**
		 * The Google token endpoint response.
		 *
		 * @var array<string,mixed> $new_token
		 */
		$new_token = $client->fetchAccessTokenWithAuthCode( $auth_code );

		if ( isset( $new_token['error'] ) ) {
			throw new Exception(
				'Failed to fetch Gmail access token: ' . esc_html( implode( ', ', array_map( 'strval', $new_token ) ) )
			);
		}

		// The whole point of the first-auth flow is to obtain the long-lived refresh token; without it
		// the token is useless, so fail loudly (it requires offline access + the consent prompt).
		if ( empty( $new_token['refresh_token'] ) ) {
			throw new Exception( 'No refresh token returned; ensure offline access and the consent prompt are requested.' );
		}

		$data = (object) array(
			'access_token'  => $new_token['access_token'],
			'expires_in'    => $new_token['expires_in'] ?? 3599,
			'scope'         => $new_token['scope'] ?? Google_Service_Gmail::GMAIL_READONLY,
			'token_type'    => $new_token['token_type'] ?? 'Bearer',
			'created'       => $new_token['created'] ?? time(),
			'refresh_token' => $new_token['refresh_token'],
		);

		return Access_Token::from_json( $data );
	}

	/**
	 * Instantiate a Google API client.
	 *
	 * Extracted so tests can substitute a mock client (the only seam needed to exercise the OAuth
	 * token flows without live credentials).
	 */
	protected function make_client(): Google_Client {
		return new Google_Client();
	}

	/**
	 * A Google client configured from the project credentials for the read-only Gmail OAuth flow.
	 *
	 * Shared by the refresh, authorization-URL, and auth-code-exchange flows.
	 *
	 * @throws Exception When no credentials are set.
	 */
	protected function make_oauth_client(): Google_Client {

		if ( ! isset( $this->credentials ) ) {
			throw new Exception( 'No credentials set on the Gmail provider.' );
		}

		$client = $this->make_client();
		$client->setLogger( $this->logger );
		$client->setScopes( Google_Service_Gmail::GMAIL_READONLY );
		$client->setAuthConfig( (array) $this->credentials->get_project_credentials() );
		$client->setAccessType( 'offline' );
		$client->setPrompt( 'consent' );

		return $client;
	}

	/**
	 * Persist the OAuth access/refresh token to a file via WP_Filesystem.
	 *
	 * The token is a secret, so the containing directory and file are created with restrictive
	 * permissions. Failure to write is logged but not fatal — the token is a cache and will be
	 * re-fetched on the next run.
	 *
	 * @param string $token_path Absolute path to the token file.
	 * @param string $contents   JSON-encoded access token.
	 */
	protected function save_access_token( string $token_path, string $contents ): void {

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		/**
		 * The WordPress filesystem abstraction.
		 *
		 * @var \WP_Filesystem_Base|null $wp_filesystem
		 */
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			$this->logger->warning( 'Could not initialise WP_Filesystem to save the Gmail access token.' );
			return;
		}

		$token_dir = dirname( $token_path );
		if ( ! $wp_filesystem->is_dir( $token_dir ) ) {
			$wp_filesystem->mkdir( $token_dir, 0700 );
		}

		if ( ! $wp_filesystem->put_contents( $token_path, $contents, 0600 ) ) {
			$this->logger->warning( 'Failed to save the Gmail access token to disk.', array( 'path' => $token_path ) );
		}
	}

	/**
	 * Returns the authorized Gmail service.
	 *
	 * Extracted so tests can substitute a mock service (the only seam needed to unit test the
	 * Gmail API calls without live credentials).
	 */
	protected function get_gmail_service(): Google_Service_Gmail {
		/**
		 * The authorized Google API client.
		 *
		 * @var Google_Client $client
		 */
		$client = $this->getClient();
		return new Google_Service_Gmail( $client );
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

		$service = $this->get_gmail_service();

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
		 * @var ListMessagesResponse $response The emails!
		 */
		$response = $service->users_messages->listUsersMessages( 'me', $opts );

		$parser   = new MailMimeParser();
		$messages = $response->getMessages();
		foreach ( $messages as $message ) {

			// Request format=raw to receive the full RFC 2822 message (base64url-encoded);
			// the response also carries labelIds, from which the read state is derived.
			$single_message = $service->users_messages->get( 'me', $message->id, array( 'format' => 'raw' ) );

			$raw = $single_message->getRaw();
			if ( empty( $raw ) ) {
				continue;
			}

			/**
			 * Legitimate use of `base64_decode()`.
			 *
			 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			 */
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
		/**
		 * Base64-decoding for rfc4648 encoded messages.
		 *
		 * @see https://php.net/manual/en/function.base64-decode.php#118244
		 * @see https://www.rfc-editor.org/info/rfc4648/
		 *
		 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		 */
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

		$service = $this->get_gmail_service();

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

		$service = $this->get_gmail_service();

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
	 * Delete the email on the server by moving it to Trash.
	 *
	 * Locates the message the same way as get_is_marked_read() — by the stored Gmail message id,
	 * falling back to a `rfc822msgid:` search. Trash (rather than permanent `delete`) is used: it is
	 * reversible and needs only the modify scope, whereas permanent deletion requires full mailbox
	 * access.
	 *
	 * @param Remote_Email_Coordinates $coordinates How to locate the email on the remote server.
	 *
	 * @return bool True when the message was found and trashed.
	 * @throws \Exception When the email cannot be found on the server.
	 */
	public function do_delete_on_server( Remote_Email_Coordinates $coordinates ): bool {

		$service = $this->get_gmail_service();

		$message = $this->get_message_by_remote_uid( $service, $coordinates->remote_uid )
			?? $this->get_message_by_rfc822_id( $service, $coordinates->message_id );

		if ( is_null( $message ) ) {
			$this->logger->warning(
				'Could not find email on the server to delete.',
				array(
					'message_id' => $coordinates->message_id,
					'remote_uid' => $coordinates->remote_uid,
				)
			);
			throw new \Exception( 'Could not find email on the server to delete.' );
		}

		$service->users_messages->trash( 'me', $message->getId() );

		return true;
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

	/**
	 * Gmail API allows marking messages as read/unread.
	 */
	public function can_mark_read(): bool {
		return true;
	}

	/**
	 * Gmail allows deleting messages on the server.
	 */
	public function can_delete_on_server(): bool {
		return true;
	}

	/**
	 * Gmail allows querying the read/unread status of messages.
	 */
	public function can_read_status(): bool {
		return true;
	}
}

<?php
/**
 * A REST endpoint that takes a raw MIME email and creates a stored email post.
 *
 * The Cloudflare Email Routing worker (see /cloudflare-worker/PLAN.md) discovers this endpoint
 * via the `email_ingress_endpoints` key in the REST index and POSTs each incoming email as
 * `message/rfc822`, authenticated with a WordPress application password.
 *
 * TODO: Allow somewhere to add a note to document details about e.g. the cloudflare worker setup URL
 * TODO: The Cloudflare worker should, ala referrer, include a header about itself in HTTP requests.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Rest;

use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Repository_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Receives raw MIME emails POSTed to the REST API and stores them, filed under an
 * auto-created "ingress" email account. Receive-only: does not implement Supports_Fetching.
 */
class REST_Ingress_Connection implements Email_Connection_Interface {

	/**
	 * Fallback advertised maximum message size when `post_max_size` is unlimited.
	 *
	 * 33554432 = 32 Mb.
	 *
	 * @see self::get_max_message_size_bytes()
	 */
	protected const DEFAULT_MAX_MESSAGE_SIZE_BYTES = 33554432;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $mailboxes_settings       Plugin settings, incl. the REST namespace.
	 * @param Email_Repository_Interface         $email_repository         Persists the received emails.
	 * @param Email_Account_WP_Post_Repository   $email_account_repository Persists the auto-created ingress account.
	 * @param ?Private_Uploads_API_Interface     $private_uploads          Private uploads API, or null to skip attachment saving.
	 * @param LoggerInterface                    $logger                   PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $mailboxes_settings,
		protected Email_Repository_Interface $email_repository,
		protected Email_Account_WP_Post_Repository $email_account_repository,
		protected ?Private_Uploads_API_Interface $private_uploads,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * Register the ingress route, unless the settings do not provide a REST namespace.
	 *
	 * @hook rest_api_init
	 * @see rest_get_server()
	 */
	public function rest_init(): void {
		if ( empty( $this->mailboxes_settings->get_rest_namespace() ) ) {
			return;
		}

		$this->register_rest_route();
	}

	/**
	 * Register the POST `{namespace}/v2/{emails-cpt-dashed}/new` route.
	 */
	protected function register_rest_route(): void {
		register_rest_route(
			$this->mailboxes_settings->get_rest_namespace() . '/v2',
			sprintf(
				'%s/new',
				$this->mailboxes_settings->get_emails_cpt_dashed(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_new_email' ),
				'permission_callback' => array( $this, 'create_new_email_permission_callback' ),
			)
		);
	}

	/**
	 * Require the emails CPT's create capability (`edit_posts` until granular capabilities are added).
	 *
	 * The Cloudflare worker authenticates with an application password over Basic auth.
	 *
	 * @return bool|WP_Error True when allowed; WP_Error 401 (unauthenticated) or 403 (forbidden) otherwise.
	 */
	public function create_new_email_permission_callback() {
		$post_type_object        = get_post_type_object( $this->mailboxes_settings->get_emails_cpt_underscored_20() );
		$create_posts_capability = $post_type_object->cap->create_posts ?? 'edit_posts';
		if ( ! is_string( $create_posts_capability ) ) {
			$create_posts_capability = 'edit_posts';
		}

		if ( current_user_can( $create_posts_capability ) ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_create',
			__( 'Sorry, you are not allowed to create emails.', 'bh-wp-mailboxes' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Advertise the ingress endpoint in the REST index so the Cloudflare worker can discover it
	 * without knowing the namespace. Appends, so multiple library instances each advertise.
	 *
	 * @see /cloudflare-worker/PLAN.md "Ingress contract"
	 *
	 * @hook rest_index
	 *
	 * @param WP_REST_Response $response The REST index response.
	 */
	public function add_email_ingress_endpoint_to_index( WP_REST_Response $response ): WP_REST_Response {
		$rest_namespace = $this->mailboxes_settings->get_rest_namespace();
		if ( empty( $rest_namespace ) ) {
			return $response;
		}

		/**
		 * The REST index data.
		 *
		 * @var array<string, mixed> $data
		 */
		$data = (array) $response->get_data();

		$existing_endpoints = $data['email_ingress_endpoints'] ?? array();
		if ( ! is_array( $existing_endpoints ) ) {
			$existing_endpoints = array();
		}
		$existing_endpoints[] = array(
			'version'                => 1,
			'namespace'              => $rest_namespace . '/v2',
			'url'                    => rest_url(
				sprintf(
					'%s/v2/%s/new',
					$rest_namespace,
					$this->mailboxes_settings->get_emails_cpt_dashed(),
				)
			),
			'accepts'                => 'message/rfc822',
			'max_message_size_bytes' => $this->get_max_message_size_bytes(),
		);

		$data['email_ingress_endpoints'] = $existing_endpoints;

		$response->set_data( $data );

		return $response;
	}

	/**
	 * The largest raw message the endpoint can accept. The body arrives as a plain POST,
	 * so PHP's `post_max_size` is the true ceiling.
	 */
	protected function get_max_message_size_bytes(): int {
		$post_max_size_bytes = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		if ( $post_max_size_bytes <= 0 ) {
			$post_max_size_bytes = self::DEFAULT_MAX_MESSAGE_SIZE_BYTES;
		}

		/**
		 * Filter the maximum raw MIME message size advertised in the REST index.
		 *
		 * @param int $post_max_size_bytes The maximum message size in bytes.
		 * @param BH_WP_Mailboxes_Settings_Interface $mailbox_settings What mailbox instance is calling the filter.
		 */
		return (int) apply_filters( 'bh_wp_mailboxes_max_message_size_bytes', $post_max_size_bytes, $this->mailboxes_settings );
	}

	/**
	 * A short human-readable name for the connection type, for display in the UI.
	 */
	public function get_friendly_name(): string {
		return __( 'Email REST Ingress', 'bh-wp-mailboxes' );
	}

	/**
	 * Find or create the email-account post that REST-ingested emails are filed under.
	 *
	 * TODO: how to configure filters and delete-after-x-days? Maybe mailbox level settings that are inherited by accounts?
	 *
	 * @throws Exception When the account cannot be created.
	 */
	public function get_email_account_wp_post_for_mailbox(): BH_Email_Account {

		$email_address = sprintf(
			'%s@%s',
			$this->mailboxes_settings->get_rest_namespace(),
			(string) wp_parse_url( site_url(), PHP_URL_HOST )
		);

		$existing = $this->email_account_repository->find_by_email_address( $email_address );

		if ( $existing ) {
			return $existing;
		}

		try {
			return $this->email_account_repository->save_new(
				email_address: $email_address,
				display_name: $this->get_friendly_name(),
				connection_type_class: self::class,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null, // Not relevant for REST (does not have Supports_Fetching interface).
				delete_local_emails_after_n_days: null,
			);
		} catch ( Exception $exception ) {
			// A concurrent request may have created the account between the find and the save.
			$existing = $this->email_account_repository->find_by_email_address( $email_address );
			if ( $existing ) {
				return $existing;
			}
			throw $exception;
		}
	}

	/**
	 * Handle a POSTed raw MIME email: parse it and store it as an email post.
	 *
	 * Idempotent: retries with the same message result in HTTP 200 and the existing post.
	 * 4xx responses signal permanent failure (the worker will not retry); 500 signals a
	 * transient failure (the sending server retries).
	 *
	 * @param WP_REST_Request $request The REST request whose body is the raw RFC 5322 message.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_new_email( WP_REST_Request $request ) {

		$content_type = $request->get_content_type();

		if ( ! is_array( $content_type ) || 'message/rfc822' !== $content_type['value'] ) {
			return new WP_Error(
				'rest_invalid_content_type',
				__( 'Content-Type must be message/rfc822.', 'bh-wp-mailboxes' ),
				array( 'status' => 415 )
			);
		}

		$raw_mime = $request->get_body();

		if ( '' === trim( $raw_mime ) ) {
			return new WP_Error(
				'rest_empty_message',
				__( 'The request body is empty.', 'bh-wp-mailboxes' ),
				array( 'status' => 400 )
			);
		}

		try {
			$parser  = new MailMimeParser();
			$message = $parser->parse( $raw_mime, true );
		} catch ( Throwable $exception ) {
			$this->logger->info( 'Failed to parse POSTed MIME message: ' . $exception->getMessage() );
			return new WP_Error(
				'rest_unparseable_message',
				__( 'The request body could not be parsed as a MIME message.', 'bh-wp-mailboxes' ),
				array( 'status' => 400 )
			);
		}

		// Emails are not guaranteed to have a Message-ID; fall back to a digest of the raw
		// message so worker/SMTP retries remain idempotent.
		$message_id = $message->getMessageId() ?? 'sha256:' . hash( 'sha256', $raw_mime );

		try {
			$email_account = $this->get_email_account_wp_post_for_mailbox();

			$is_duplicate = $this->email_repository->is_post_for_message_id(
				$email_account->get_account_email_address(),
				$message_id
			);

			$bh_email = $this->email_repository->save_new(
				new Fetched_Email(
					$message,
					new Remote_Email_Coordinates( $message_id ),
				),
				$this->mailboxes_settings,
				$email_account,
				private_uploads: $this->private_uploads,
			);
		} catch ( Throwable $exception ) {
			$this->logger->error(
				'Failed to save REST-ingested email: ' . $exception->getMessage(),
				array( 'exception' => $exception )
			);
			return new WP_Error(
				'rest_email_not_saved',
				__( 'The email could not be saved.', 'bh-wp-mailboxes' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'post_id'    => $bh_email->get_post_id(),
				'message_id' => $message_id,
			),
			$is_duplicate ? 200 : 201,
		);
	}

	/**
	 * TODO: GET /wp-json/ and check the expected endpoint exists.
	 */
	public function test_connection(): bool {
		return true;
	}
}

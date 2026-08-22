<?php
/**
 * REST endpoints used to arrange and assert end-to-end tests for bh-wp-mailboxes.
 *
 * Following the e2e philosophy (arrange/assert via REST, drive the UI minimally), these endpoints
 * let a Playwright test read the plugin's state and create fixture data without clicking through the
 * admin UI. They are registered only in the development-plugin, so they never reach production.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_E2E_Connection;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Mailbox_Settings;
use Exception;
use Psr\Log\NullLogger;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * `GET    /wp-json/bh-wp-mailboxes-dev/v2/status`   — is the library active + how many email posts exist.
 * `POST   /wp-json/bh-wp-mailboxes-dev/v2/accounts` — create a fixture email account.
 * `POST   /wp-json/bh-wp-mailboxes-dev/v2/emails`   — create a fixture email post for assertions.
 * `DELETE /wp-json/bh-wp-mailboxes-dev/v2/emails`   — delete every fixture email post (reset).
 * `POST   /wp-json/bh-wp-mailboxes-dev/v2/fetch`    — run the fetch for the registered mailboxes.
 *
 * Fixtures are stored through the library's own repositories (the same code the production fetch and
 * REST-ingress paths use), so what the tests arrange is byte-for-byte what production would store; only
 * the e2e-specific knobs (post_status, tri-state remote flags) are applied on top.
 */
class Mailboxes {

	const NAMESPACE = 'bh-wp-mailboxes-dev/v2';

	/**
	 * The account fixture emails are filed under when a test does not supply `account_id`
	 * (`Email_WP_Post_Repository::save_new()` requires an account). Created on first use.
	 */
	const FIXTURE_ACCOUNT_EMAIL_ADDRESS = 'e2e-fixtures@bh-wp-mailboxes.test';

	/**
	 * Deliberately not a real class: no `bh_wp_mailboxes_connection_for_account` filter resolves it, so
	 * emails filed under the default fixture account keep behaving as "no linked connection" (no
	 * remote-status badges, no "Connection:" line), as when they were parented to no account at all.
	 */
	const NO_CONNECTION_TYPE_CLASS = 'BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\No_Connection';

	/**
	 * Constructor.
	 *
	 * @param Mailbox_Settings $e2e_mailbox_settings The e2e mailbox's settings: its CPTs are where fixtures are arranged.
	 */
	public function __construct(
		protected Mailbox_Settings $e2e_mailbox_settings,
	) {
	}

	/**
	 * The emails CPT of the dedicated e2e mailbox (friendly name "E2E Email"). Arranging here keeps the
	 * human-facing "Fixtures" demo mailbox untouched by test runs.
	 *
	 * @see development-plugin.php — $e2e_mailboxes_settings
	 * @see \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT
	 */
	const EMAIL_POST_TYPE = Mock_Mailbox_E2E_Connection::EMAILS_CPT;

	/**
	 * The accounts CPT of the e2e mailbox (friendly name "E2E Accounts").
	 *
	 * @see development-plugin.php — $e2e_mailboxes_settings
	 */
	const ACCOUNT_POST_TYPE = Mock_Mailbox_E2E_Connection::ACCOUNTS_CPT;

	/**
	 * Register the REST routes.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', $this->register_routes( ... ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @hooked rest_api_init
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => $this->get_status( ... ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/debug-log',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => $this->get_debug_log( ... ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => $this->truncate_debug_log( ... ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/accounts',
			array(
				'methods'             => 'POST',
				'callback'            => $this->create_account( ... ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email_address' => array(
						'type'     => 'string',
						'required' => true,
					),
					'display_name'  => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/emails',
			array(
				'methods'             => 'POST',
				'callback'            => $this->create_email( ... ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'subject'           => array(
						'type'     => 'string',
						'required' => false,
					),
					'body_plain'        => array(
						'type'     => 'string',
						'required' => false,
					),
					'body_html'         => array(
						'type'     => 'string',
						'required' => false,
					),
					'post_status'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'account_id'        => array(
						'type'     => 'integer',
						'required' => false,
					),
					'is_read'           => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'deleted_on_server' => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'has_attachment'    => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'date_header'       => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/emails',
			array(
				'methods'             => 'DELETE',
				'callback'            => $this->delete_emails( ... ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fixtures-fail',
			array(
				'methods'             => 'POST',
				'callback'            => $this->set_fixtures_fail( ... ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email_address' => array(
						'type'     => 'string',
						'required' => true,
					),
					'enabled'       => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fetch',
			array(
				'methods'             => 'POST',
				'callback'            => $this->run_fetch( ... ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'account_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * The path to the WordPress debug log. The wp-env config points WP_DEBUG_LOG at this exact path.
	 */
	private function debug_log_path(): string {
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Return the contents of wp-content/debug.log (empty string when absent).
	 *
	 * Used by the Playwright global teardown to fail the run if the suite emitted PHP notices/errors.
	 * Returns { contents: string } with HTTP 200.
	 */
	public function get_debug_log(): WP_REST_Response {

		$path     = $this->debug_log_path();
		$contents = is_readable( $path )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, dev only.
			? (string) file_get_contents( $path )
			: '';

		return new WP_REST_Response( array( 'contents' => $contents ), 200 );
	}

	/**
	 * Truncate wp-content/debug.log so a test run starts from a clean slate.
	 *
	 * Returns { truncated: bool } with HTTP 200.
	 */
	public function truncate_debug_log(): WP_REST_Response {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_put_contents -- local file, dev only.
		$truncated = false !== file_put_contents( $this->debug_log_path(), '' );

		return new WP_REST_Response( array( 'truncated' => $truncated ), 200 );
	}

	/**
	 * Report whether the library loaded and how many email posts currently exist.
	 */
	public function get_status(): WP_REST_Response {

		$library_loaded = class_exists( \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes::class );

		$count = (int) ( wp_count_posts( self::EMAIL_POST_TYPE )->publish ?? 0 );

		return new WP_REST_Response(
			array(
				'library_loaded' => $library_loaded,
				'email_count'    => $count,
			),
			200
		);
	}

	/**
	 * Create a fixture email account post for e2e tests, via the library's account repository
	 * (the same path `BH_WP_Mailboxes::add_email_account()` and the REST-ingress connection use).
	 *
	 * Required body param: email_address.
	 * Optional: display_name.
	 *
	 * Returns { post_id: int } with HTTP 201 (idempotent when the account already exists).
	 *
	 * @param WP_REST_Request $request The REST request object.
	 */
	public function create_account( WP_REST_Request $request ): WP_REST_Response {

		$email_address = sanitize_email( (string) $request->get_param( 'email_address' ) );
		$display_name  = is_string( $request->get_param( 'display_name' ) )
			? sanitize_text_field( $request->get_param( 'display_name' ) )
			: $email_address;

		try {
			$account = $this->account_repository()->save_new(
				email_address: $email_address,
				display_name: $display_name,
				connection_type_class: Mock_Mailbox_E2E_Connection::class,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null,
				delete_local_emails_after_n_days: null,
			);
		} catch ( Throwable $throwable ) {
			// The account may already exist from an earlier request.
			$account = $this->account_repository()->find_by_email_address( $email_address );
			if ( null === $account ) {
				return new WP_REST_Response( array( 'error' => $throwable->getMessage() ), 500 );
			}
		}

		return new WP_REST_Response( array( 'post_id' => $account->get_post_id() ), 201 );
	}

	/**
	 * Create a fixture email post so a test can assert it appears in the admin.
	 *
	 * The email is built as a MIME message and stored via `Email_WP_Post_Repository::save_new()` — the
	 * same production pipeline the fetch and REST-ingress paths use — so parsing, storage encoding, and
	 * the Date/From/Subject headers all behave exactly as for a real email. The e2e-only knobs
	 * (post_status, tri-state remote flags, the attachment shim) are applied afterwards.
	 *
	 * Supported body params: subject, body_plain, body_html, post_status, account_id,
	 * is_read (bool), deleted_on_server (bool), has_attachment (bool), date_header.
	 *
	 * Returns { post_id: int } with HTTP 201.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 */
	public function create_email( WP_REST_Request $request ): WP_REST_Response {

		$subject = is_string( $request->get_param( 'subject' ) )
			? sanitize_text_field( $request->get_param( 'subject' ) )
			: 'E2E fixture email';

		$body_plain = is_string( $request->get_param( 'body_plain' ) ) ? $request->get_param( 'body_plain' ) : '';
		$body_html  = is_string( $request->get_param( 'body_html' ) ) ? $request->get_param( 'body_html' ) : '';

		$post_status = is_string( $request->get_param( 'post_status' ) )
			? sanitize_key( $request->get_param( 'post_status' ) )
			: 'bh_email_new';

		// Optionally parent the email to an account post (emails store their account as post_parent), so a
		// test can filter the list to just its own emails via the account dropdown / `bh_email_account` arg.
		$account_id = is_numeric( $request->get_param( 'account_id' ) ) ? (int) $request->get_param( 'account_id' ) : 0;

		$date_header = is_string( $request->get_param( 'date_header' ) )
			? sanitize_text_field( $request->get_param( 'date_header' ) )
			: null;

		$is_read           = $request->get_param( 'is_read' );
		$deleted_on_server = $request->get_param( 'deleted_on_server' );

		// A unique Message-ID keeps the repository's dedupe from matching an earlier fixture email.
		$message_id = sprintf( '<e2e-fixture-%s@bh-wp-mailboxes.test>', wp_generate_uuid4() );

		$raw_mime = $this->build_mime( $subject, $message_id, $body_plain, $body_html, $date_header );

		try {
			$message = new MailMimeParser()->parse( $raw_mime, true );

			$bh_email = $this->email_repository()->save_new(
				new Fetched_Email(
					$message,
					new Remote_Email_Coordinates( $message_id ),
					is_remote_read: true === $is_read,
				),
				$this->e2e_mailbox_settings,
				$this->get_email_account( $account_id ),
			);

			// The production pipeline always saves as `bh_email_new`; other fixture statuses are an update.
			if ( 'bh_email_new' !== $post_status ) {
				$bh_email = $this->email_repository()->update( $bh_email, local_status: $post_status );
			}

			if ( true === $deleted_on_server ) {
				$this->email_repository()->update( $bh_email, is_remote_deleted: true );
			}
		} catch ( Throwable $throwable ) {
			return new WP_REST_Response( array( 'error' => $throwable->getMessage() ), 500 );
		}

		$post_id = $bh_email->get_post_id();

		// The UI treats missing remote-state meta as "unknown", but the production save always writes
		// it; remove it when the test did not specify a value so absent means absent.
		if ( null === $is_read ) {
			delete_post_meta( $post_id, 'is_remote_read' );
		}
		if ( null === $deleted_on_server ) {
			delete_post_meta( $post_id, 'is_remote_deleted' );
		}

		// Shim: a real attachment would need private-uploads wiring; the attachments-metabox test only
		// needs a child attachment post to exist.
		$has_attachment = $request->get_param( 'has_attachment' );
		if ( true === $has_attachment ) {
			wp_insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'test-attachment.txt',
					'post_parent'    => $post_id,
					'post_mime_type' => 'text/plain',
				)
			);
		}

		return new WP_REST_Response( array( 'post_id' => $post_id ), 201 );
	}

	/**
	 * The account to file a fixture email under: the given account, or a default no-connection
	 * account created on first use (mirrors `REST_Ingress_Connection::get_email_account_wp_post_for_mailbox()`).
	 *
	 * @param int $account_id A fixture account's post ID, or 0 for the default account.
	 *
	 * @throws Exception When the default account cannot be created.
	 * @throws \InvalidArgumentException When no account exists for the given post ID.
	 */
	private function get_email_account( int $account_id ): BH_Email_Account {

		if ( $account_id > 0 ) {
			return $this->account_repository()->find_by_post_id( $account_id );
		}

		$existing = $this->account_repository()->find_by_email_address( self::FIXTURE_ACCOUNT_EMAIL_ADDRESS );
		if ( null !== $existing ) {
			return $existing;
		}

		try {
			return $this->account_repository()->save_new(
				email_address: self::FIXTURE_ACCOUNT_EMAIL_ADDRESS,
				display_name: 'E2E fixture emails',
				connection_type_class: self::NO_CONNECTION_TYPE_CLASS,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null,
				delete_local_emails_after_n_days: null,
			);
		} catch ( Exception $exception ) {
			// A concurrent request may have created the account between the find and the save.
			$existing = $this->account_repository()->find_by_email_address( self::FIXTURE_ACCOUNT_EMAIL_ADDRESS );
			if ( null !== $existing ) {
				return $existing;
			}
			throw $exception;
		}
	}

	/**
	 * The library's email repository for the e2e mailbox's CPT.
	 */
	private function email_repository(): Email_WP_Post_Repository {
		$logger = new NullLogger();
		return new Email_WP_Post_Repository(
			$this->e2e_mailbox_settings->get_emails_cpt_underscored_20(),
			new BH_Email_Factory( $logger ),
			$logger,
		);
	}

	/**
	 * The library's email-account repository for the e2e mailbox's accounts CPT.
	 */
	private function account_repository(): Email_Account_WP_Post_Repository {
		$logger = new NullLogger();
		return new Email_Account_WP_Post_Repository(
			$this->e2e_mailbox_settings->get_email_accounts_cpt_underscored_20(),
			new BH_Email_Account_Factory( $logger ),
			$logger,
		);
	}

	/**
	 * Delete every fixture email post and clear per-user fixture read/unread/deleted state.
	 *
	 * Used by Playwright tests to reset to a clean slate between scenarios.
	 *
	 * Returns { deleted: int } with HTTP 200.
	 */
	public function delete_emails(): WP_REST_Response {

		$email_post_ids = get_posts(
			array(
				'post_type'   => self::EMAIL_POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		$deleted = 0;
		foreach ( $email_post_ids as $post_id ) {
			if ( null !== wp_delete_post( (int) $post_id, true ) ) {
				++$deleted;
			}
		}

		// Clear the per-user fixture state written by the e2e connection, for every user.
		$prefix = Mock_Mailbox_E2E_Connection::META_KEY_PREFIX;
		foreach (
			array(
				$prefix . 'is_remote_deleted',
				$prefix . 'is_remote_read',
				$prefix . 'is_remote_unread',
			) as $meta_key
		) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}

		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * Toggle the simulated-connection-failure flag for a single fixtures account.
	 *
	 * With `enabled` true (default), the fixtures connection throws when fetching the named account, so the
	 * API records a failed-login time and the auth-failure admin notice appears. With `enabled` false the
	 * flag is cleared. Scoped to one account so it does not disturb other accounts' fetches. Returns
	 * { email_address: string, enabled: bool } with HTTP 200.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 */
	public function set_fixtures_fail( WP_REST_Request $request ): WP_REST_Response {

		$email_address = sanitize_email( (string) $request->get_param( 'email_address' ) );
		$enabled       = false === $request->get_param( 'enabled' ) ? false : true;

		if ( $enabled ) {
			update_option( Mock_Mailbox_E2E_Connection::FAIL_ACCOUNT_OPTION, $email_address, false );
		} else {
			delete_option( Mock_Mailbox_E2E_Connection::FAIL_ACCOUNT_OPTION );
		}

		return new WP_REST_Response(
			array(
				'email_address' => $email_address,
				'enabled'       => $enabled,
			),
			200
		);
	}

	/**
	 * Run the email fetch and report how many new emails were saved.
	 *
	 * Mirrors the Settings "Run now" button so Playwright can drive the fetch pipeline via REST. Without
	 * arguments it fetches every registered mailbox; passing `account_id` fetches just that one account,
	 * which lets parallel tests arrange their own account without racing each other's dedup on shared ones.
	 *
	 * Returns { fetched: int } with HTTP 200.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 */
	public function run_fetch( WP_REST_Request $request ): WP_REST_Response {

		// (The wp_tempnam() workaround previously here is no longer needed: the repository now loads
		// wp-admin/includes/file.php itself when saving attachments outside admin — see Email_WP_Post_Repository.)
		$account_id = is_numeric( $request->get_param( 'account_id' ) ) ? (int) $request->get_param( 'account_id' ) : 0;

		$mailboxes = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), 'development-plugin' );

		$fetched = 0;
		foreach ( (array) $mailboxes as $api ) {
			if ( ! $api instanceof API_Interface ) {
				continue;
			}
			try {
				if ( $account_id > 0 ) {
					$account = null;
					foreach ( $api->get_email_accounts() as $candidate ) {
						if ( $candidate->get_post_id() === $account_id ) {
							$account = $candidate;
							break;
						}
					}
					if ( null === $account ) {
						continue;
					}
					$fetched += count( $api->check_email_for_account( $account )->new_emails );
				} else {
					$fetched += count( $api->check_email()->get_emails() );
				}
			} catch ( Throwable $t ) {
				// A test mailbox may be unreachable; don't fail the whole request.
				continue;
			}
		}

		return new WP_REST_Response( array( 'fetched' => $fetched ), 200 );
	}

	/**
	 * Build a minimal RFC2822 MIME message from plain and/or HTML body parts.
	 *
	 * The headers (From, Subject, Message-ID, Date) are real message headers, parsed by the production
	 * pipeline exactly as for a fetched or REST-ingested email.
	 *
	 * @param string  $subject     The email subject.
	 * @param string  $message_id  The Message-ID header value.
	 * @param string  $plain       Plain-text body (may be empty).
	 * @param string  $html        HTML body (may be empty).
	 * @param ?string $date_header RFC2822 Date header value, or null for no Date header.
	 */
	protected function build_mime( string $subject, string $message_id, string $plain, string $html, ?string $date_header ): string {

		$headers  = "From: fixture@bh-wp-mailboxes.test\r\n";
		$headers .= "Subject: $subject\r\n";
		$headers .= "Message-ID: $message_id\r\n";
		if ( null !== $date_header && '' !== $date_header ) {
			$headers .= "Date: $date_header\r\n";
		}
		$headers .= "MIME-Version: 1.0\r\n";

		if ( '' !== $plain && '' !== $html ) {
			$boundary = '----=_Part_' . md5( $plain . $html );
			return $headers
				. "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n"
				. "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$plain\r\n"
				. "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n"
				. "--$boundary--\r\n";
		}
		if ( '' !== $html ) {
			return $headers . "Content-Type: text/html; charset=UTF-8\r\n\r\n$html";
		}
		return $headers . "Content-Type: text/plain; charset=UTF-8\r\n\r\n$plain";
	}
}

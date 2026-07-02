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
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET    /wp-json/bh-wp-mailboxes-dev/v1/status`   — is the library active + how many email posts exist.
 * `POST   /wp-json/bh-wp-mailboxes-dev/v1/accounts` — create a fixture email account.
 * `POST   /wp-json/bh-wp-mailboxes-dev/v1/emails`   — create a fixture email post for assertions.
 * `DELETE /wp-json/bh-wp-mailboxes-dev/v1/emails`   — delete every fixture email post (reset).
 * `POST   /wp-json/bh-wp-mailboxes-dev/v1/fetch`    — run the fetch for the registered mailboxes.
 */
class Mailboxes {

	const NAMESPACE = 'bh-wp-mailboxes-dev/v1';

	/**
	 * The emails CPT registered by the fixtures mailbox instance (friendly name "Fixtures Email").
	 *
	 * @see development-plugin.php — $fixtures_mailboxes_settings
	 * @see \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT
	 */
	const EMAIL_POST_TYPE = 'fixtures_email';

	/**
	 * The accounts CPT registered by the fixtures mailbox instance (friendly name "Fixtures Accounts").
	 *
	 * @see development-plugin.php — $fixtures_mailboxes_settings
	 */
	const ACCOUNT_POST_TYPE = 'fixtures_accounts';

	/**
	 * The connection class the fixtures mailbox uses; accounts must reference it so the
	 * `bh_wp_mailboxes_connection_for_account` filter resolves the fixtures connection for them.
	 *
	 * @see \BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_Fixtures_Connection
	 */
	const ACCOUNT_PROVIDER_CLASS = 'BrianHenryIE\\\\WP_Mailboxes_Development_Plugin\\\\Connections\\\\Mock_Mailbox_Fixtures_Connection';

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
	 * Create a fixture email account post for e2e tests.
	 *
	 * Required body param: email_address.
	 * Optional: display_name.
	 *
	 * Returns { post_id: int } with HTTP 201.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 */
	public function create_account( WP_REST_Request $request ): WP_REST_Response {

		$email_address = sanitize_email( (string) $request->get_param( 'email_address' ) );
		$display_name  = is_string( $request->get_param( 'display_name' ) )
			? sanitize_text_field( $request->get_param( 'display_name' ) )
			: $email_address;

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::ACCOUNT_POST_TYPE,
				'post_status' => 'bh_email_ac_active',
				'post_title'  => $display_name,
				'post_name'   => sanitize_title( $email_address ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => $post_id->get_error_message() ), 500 );
		}

		update_post_meta( $post_id, 'email_address', $email_address );
		update_post_meta( $post_id, 'display_name', $display_name );
		update_post_meta( $post_id, 'connection_type_class', self::ACCOUNT_PROVIDER_CLASS );

		return new WP_REST_Response( array( 'post_id' => $post_id ), 201 );
	}

	/**
	 * Create a fixture email post so a test can assert it appears in the admin.
	 *
	 * Supported body params: subject, body_plain, body_html, post_status,
	 * is_read (bool), deleted_on_server (bool), has_attachment (bool).
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

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::EMAIL_POST_TYPE,
				'post_status' => $post_status,
				'post_title'  => $subject,
				'post_parent' => $account_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => $post_id->get_error_message() ), 500 );
		}

		// Store body content as MIME so BH_Email_Factory::from_wp_post() (which uses MailMimeParser
		// on post_content) can read it correctly. Bypass content_save_pre to avoid filter mangling.
		if ( '' !== $body_plain || '' !== $body_html ) {
			/**
			 * The WordPress global database object.
			 *
			 * @var \wpdb $wpdb
			 */
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $wpdb->posts, array( 'post_content' => $this->build_mime( $body_plain, $body_html ) ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}

		$is_read = $request->get_param( 'is_read' );
		if ( null !== $is_read ) {
			update_post_meta( $post_id, 'is_remote_read', true === $is_read ? 'yes' : 'no' );
		}

		$deleted_on_server = $request->get_param( 'deleted_on_server' );
		if ( true === $deleted_on_server ) {
			update_post_meta( $post_id, 'is_remote_deleted', 'yes' );
		}

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

		$date_header = $request->get_param( 'date_header' );
		if ( is_string( $date_header ) && '' !== $date_header ) {
			$existing = get_post_meta( $post_id, 'headers', true );
			$headers  = is_array( $existing ) ? $existing : array();
			if ( ! in_array( 'Date', $headers, true ) ) {
				$headers[] = 'Date';
			}
			update_post_meta( $post_id, 'headers', $headers );
			update_post_meta( $post_id, 'Date', sanitize_text_field( $date_header ) );
		}

		return new WP_REST_Response( array( 'post_id' => $post_id ), 201 );
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

		// Clear the per-user fixture state written by Mock_Mailbox_Fixtures_Connection, for every user.
		foreach (
			array(
				'_mock_mailbox_fixtures_connection_is_remote_deleted',
				'_mock_mailbox_fixtures_connection_is_remote_read',
				'_mock_mailbox_fixtures_connection_is_remote_unread',
			) as $meta_key
		) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}

		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
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

		// The fetch pipeline saves attachments via wp_tempnam(), which lives in wp-admin/includes/file.php.
		// The admin "Run now" button and admin-ajax "Check now" have it loaded; a plain REST request does not.
		require_once ABSPATH . 'wp-admin/includes/file.php';

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
	 * @param string $plain Plain-text body (may be empty).
	 * @param string $html  HTML body (may be empty).
	 */
	protected function build_mime( string $plain, string $html ): string {
		if ( '' !== $plain && '' !== $html ) {
			$boundary = '----=_Part_' . md5( $plain . $html );
			return "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n"
				. "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$plain\r\n"
				. "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n"
				. "--$boundary--\r\n";
		}
		if ( '' !== $html ) {
			return "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html";
		}
		return "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$plain";
	}
}

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

use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET  /wp-json/bh-wp-mailboxes-dev/v1/status`     — is the library active + how many email posts exist.
 * `POST /wp-json/bh-wp-mailboxes-dev/v1/emails`     — create a fixture email post for assertions.
 */
class Mailboxes {

	const NAMESPACE = 'bh-wp-mailboxes-dev/v1';

	/**
	 * The custom post type the library registers for stored emails.
	 *
	 * @see \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT
	 */
	const EMAIL_POST_TYPE = 'bh_wp_mailboxes_cpt';

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
	}

	/**
	 * Report whether the library loaded and how many email posts currently exist.
	 */
	public function get_status(): WP_REST_Response {

		$library_loaded = class_exists( \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes::class );

		$count = (int) wp_count_posts( self::EMAIL_POST_TYPE )->publish;

		return new WP_REST_Response(
			array(
				'library_loaded' => $library_loaded,
				'email_count'    => $count,
			),
			200
		);
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
			: 'publish';

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::EMAIL_POST_TYPE,
				'post_status' => $post_status,
				'post_title'  => $subject,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => $post_id->get_error_message() ), 500 );
		}

		// Store body content as MIME so BH_Email_Factory::from_wp_post() (which uses MailMimeParser
		// on post_content) can read it correctly. Bypass content_save_pre to avoid filter mangling.
		if ( '' !== $body_plain || '' !== $body_html ) {
			global $wpdb;
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

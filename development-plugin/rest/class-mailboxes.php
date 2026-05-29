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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @hooked rest_api_init
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/emails',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_email' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'subject' => array(
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
	 * Create a fixture email post so a test can assert it appears in the admin list.
	 */
	public function create_email( WP_REST_Request $request ): WP_REST_Response {

		$subject = is_string( $request->get_param( 'subject' ) )
			? sanitize_text_field( $request->get_param( 'subject' ) )
			: 'E2E fixture email';

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::EMAIL_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $subject,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => $post_id->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'id' => $post_id ), 201 );
	}
}

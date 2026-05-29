<?php
/**
 * Some questionable convenience functions for authentication during development and testing.
 *
 * These exist so Playwright tests can arrange state via REST and skip the login UI. They must never
 * ship — that is why they live in the development-plugin, gated on the test-plugin being active.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

use Exception;
use WP_User;

/**
 * Treat REST callers as admin, and add `?login_as_user=<user_slug>` to log in without the UI.
 */
class Authentication {

	/**
	 * Add actions/filters.
	 */
	public function register_hooks(): void {
		add_filter( 'rest_authentication_errors', array( $this, 'set_rest_user_admin' ) );
		add_action( 'init', array( $this, 'login_as_any_user' ) );
	}

	/**
	 * Make REST requests run as the first admin user, so tests can arrange/assert via REST.
	 *
	 * @param WP_Error|null|true $errors WP_Error on auth error, null if unused, true if succeeded.
	 *
	 * @hooked rest_authentication_errors
	 * @see \WP_REST_Server::check_authentication()
	 */
	public function set_rest_user_admin( $errors ): mixed {

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $errors;
		}

		wp_set_current_user( 1 );

		return $errors;
	}

	/**
	 * Log in as an arbitrary user via `?login_as_user=<user_slug>`.
	 *
	 * @hooked init
	 * @throws Exception When an invalid user is supplied.
	 */
	public function login_as_any_user(): void {
		if ( ! isset( $_GET['login_as_user'] ) ) {
			return;
		}

		$login_as_user = sanitize_text_field( wp_unslash( $_GET['login_as_user'] ) );

		/** @var WP_User|false $wp_user */
		$wp_user = get_user_by( 'slug', $login_as_user );
		if ( ! $wp_user ) {
			throw new Exception( 'Could not find user: ' . esc_html( $login_as_user ) );
		}

		wp_set_current_user( $wp_user->ID, $wp_user->user_login );
		wp_set_auth_cookie( $wp_user->ID );
	}
}

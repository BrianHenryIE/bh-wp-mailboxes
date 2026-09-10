<?php
/**
 * The constants and helper functions the Secrets API classes depend on, for when the API's own
 * `secrets.php` is not loaded (i.e. neither WordPress core nor the activated feature plugin provides it).
 *
 * The library includes only the Secrets API's class files from `wordpress/secrets-api` (see
 * {@see \BrianHenryIE\WP_Mailboxes\Secrets_API_Loader}), never its `secrets.php`, so the global
 * `wp_get_secret()` family is never defined by this library. The classes themselves reach for a
 * handful of globals from that file; each is defined here only when absent.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

if ( ! defined( 'WP_SECRETS_ERROR_DECRYPTION_FAILED' ) ) {
	define( 'WP_SECRETS_ERROR_DECRYPTION_FAILED', 'secret_decryption_failed' );
}
if ( ! defined( 'WP_SECRETS_ERROR_KEY_UNAVAILABLE' ) ) {
	define( 'WP_SECRETS_ERROR_KEY_UNAVAILABLE', 'secret_key_unavailable' );
}
if ( ! defined( 'WP_SECRETS_ERROR_STORE_UNAVAILABLE' ) ) {
	define( 'WP_SECRETS_ERROR_STORE_UNAVAILABLE', 'secret_store_unavailable' );
}
if ( ! defined( 'WP_SECRETS_ERROR_INVALID_NAME' ) ) {
	define( 'WP_SECRETS_ERROR_INVALID_NAME', 'secret_invalid_name' );
}
if ( ! defined( 'WP_SECRETS_ERROR_INVALID_VALUE' ) ) {
	define( 'WP_SECRETS_ERROR_INVALID_VALUE', 'secret_invalid_value' );
}
if ( ! defined( 'WP_SECRETS_ERROR_INVALID_ARGUMENT' ) ) {
	define( 'WP_SECRETS_ERROR_INVALID_ARGUMENT', 'secret_invalid_argument' );
}
if ( ! defined( 'WP_SECRETS_ERROR_CRYPTO_UNAVAILABLE' ) ) {
	define( 'WP_SECRETS_ERROR_CRYPTO_UNAVAILABLE', 'secret_crypto_unavailable' );
}
if ( ! defined( 'WP_SECRETS_ERROR_RECORD_MALFORMED' ) ) {
	define( 'WP_SECRETS_ERROR_RECORD_MALFORMED', 'secret_record_malformed' );
}
if ( ! defined( 'WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION' ) ) {
	define( 'WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION', 'secret_record_unsupported_version' );
}
if ( ! defined( 'WP_SECRETS_ERROR_PROVIDER_READ_ONLY' ) ) {
	define( 'WP_SECRETS_ERROR_PROVIDER_READ_ONLY', 'secret_provider_read_only' );
}
if ( ! defined( 'WP_SECRETS_RECORD_VERSION' ) ) {
	define( 'WP_SECRETS_RECORD_VERSION', 1 );
}
if ( ! defined( 'WP_SECRETS_MAX_NAME_LENGTH' ) ) {
	define( 'WP_SECRETS_MAX_NAME_LENGTH', 172 );
}
if ( ! defined( 'WP_SECRETS_CAP_MANAGE' ) ) {
	define( 'WP_SECRETS_CAP_MANAGE', 'manage_secrets' );
}
if ( ! defined( 'WP_SECRETS_CAP_MANAGE_NETWORK' ) ) {
	define( 'WP_SECRETS_CAP_MANAGE_NETWORK', 'manage_network_secrets' );
}

if ( ! function_exists( 'wp_secrets_memzero' ) ) {
	/**
	 * Best-effort zeroing of a string's memory, then clearing the variable.
	 *
	 * @param mixed $value The value to clear, by reference.
	 */
	function wp_secrets_memzero( &$value ): void {
		if ( ! is_string( $value ) ) {
			return;
		}

		if ( function_exists( 'sodium_memzero' ) ) {
			try {
				$alias =& $value;
				sodium_memzero( $alias );
			} catch ( \Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A shared buffer can refuse; the reassignment below still runs.
				// Fall through.
			}
		}

		$value = '';
	}
}

if ( ! function_exists( 'wp_secrets_validate_name' ) ) {
	/**
	 * Validate a secret's name: `namespace/key`, each segment lowercase alphanumerics, hyphens and
	 * underscores, not starting or ending with either.
	 *
	 * Unlike the API's own implementation this refuses unnamespaced names outright: nothing in this
	 * library uses them.
	 *
	 * @param mixed $name Candidate secret name.
	 *
	 * @return true|\WP_Error
	 */
	function wp_secrets_validate_name( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return new \WP_Error( WP_SECRETS_ERROR_INVALID_NAME, 'Secret names must be non-empty strings.' );
		}

		if ( strlen( $name ) > WP_SECRETS_MAX_NAME_LENGTH ) {
			return new \WP_Error( WP_SECRETS_ERROR_INVALID_NAME, sprintf( 'Secret names must be %d characters or fewer.', WP_SECRETS_MAX_NAME_LENGTH ) );
		}

		$segments = explode( '/', $name );

		if ( 2 !== count( $segments ) ) {
			return new \WP_Error( WP_SECRETS_ERROR_INVALID_NAME, 'Secret names must be "namespace/key": exactly one "/" separating two segments.' );
		}

		$segment_pattern = '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/';

		foreach ( $segments as $segment ) {
			if ( ! preg_match( $segment_pattern, $segment ) ) {
				return new \WP_Error( WP_SECRETS_ERROR_INVALID_NAME, 'Secret name segments may contain only lowercase letters, numbers, hyphens, and underscores, and must not start or end with a hyphen or underscore.' );
			}
		}

		return true;
	}
}

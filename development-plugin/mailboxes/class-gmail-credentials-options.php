<?php
/**
 * Google API credentials pasted into the dev settings page and stored as wp_options.
 *
 * An alternative to {@see Gmail_API}'s file-based credentials for environments with no
 * `/var/www/test-credentials` directory (e.g. WordPress Playground): the OAuth client secret JSON
 * and access token JSON are pasted into textareas and stored verbatim in wp_options.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use RuntimeException;
use stdClass;

/**
 * Google API credentials resolved from wp_options saved by the dev settings page.
 */
class Gmail_Credentials_Options implements Google_API_Credentials_Interface {

	public const OPTION_EMAIL_ADDRESS      = 'bh_wp_mailboxes_dev_gmail_email_address';
	public const OPTION_CLIENT_SECRET_JSON = 'bh_wp_mailboxes_dev_gmail_client_secret_json';
	public const OPTION_ACCESS_TOKEN_JSON  = 'bh_wp_mailboxes_dev_gmail_access_token_json';

	/**
	 * Persist the pasted Gmail credentials.
	 *
	 * @param string $email_address      The Gmail account's email address.
	 * @param string $client_secret_json The OAuth client secret JSON, verbatim.
	 * @param string $access_token_json  The access token JSON, verbatim (may be empty until authorized).
	 */
	public static function save( string $email_address, string $client_secret_json, string $access_token_json ): void {
		update_option( self::OPTION_EMAIL_ADDRESS, $email_address );
		update_option( self::OPTION_CLIENT_SECRET_JSON, $client_secret_json );
		update_option( self::OPTION_ACCESS_TOKEN_JSON, $access_token_json );
	}

	/**
	 * The Gmail account's email address, as entered on the settings page.
	 */
	public function get_email_address(): string {
		$email = get_option( self::OPTION_EMAIL_ADDRESS, '' );
		return is_string( $email ) ? $email : '';
	}

	/**
	 * The stored OAuth client secret JSON string.
	 */
	public function get_client_secret_json(): string {
		$json = get_option( self::OPTION_CLIENT_SECRET_JSON, '' );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * The stored access token JSON string.
	 */
	public function get_access_token_json(): string {
		$json = get_option( self::OPTION_ACCESS_TOKEN_JSON, '' );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * True when an email address and a parseable OAuth client secret have been saved.
	 */
	public function is_complete(): bool {
		if ( '' === $this->get_email_address() ) {
			return false;
		}
		try {
			$this->get_project_credentials();
		} catch ( RuntimeException $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Returns the OAuth client credentials parsed from the stored JSON.
	 *
	 * @throws RuntimeException When no valid client secret JSON has been saved.
	 */
	public function get_project_credentials(): OAuth_Client_Credentials {
		return OAuth_Client_Credentials::from_json( $this->decode( $this->get_client_secret_json(), 'client secret' ) );
	}

	/**
	 * Returns the access token parsed from the stored JSON, or null when none has been saved yet.
	 *
	 * @throws RuntimeException When the saved value is not valid JSON.
	 */
	public function get_access_token(): ?Access_Token {
		$json = $this->get_access_token_json();
		if ( '' === trim( $json ) ) {
			return null;
		}
		return Access_Token::from_json( $this->decode( $json, 'access token' ) );
	}

	/**
	 * Decode a stored JSON string to an object.
	 *
	 * @param string $json  The JSON string.
	 * @param string $label For the exception message: which credential failed to parse.
	 *
	 * @throws RuntimeException When the string is not a JSON object.
	 */
	private function decode( string $json, string $label ): stdClass {
		$decoded = json_decode( $json );
		if ( ! $decoded instanceof stdClass ) {
			throw new RuntimeException( 'The saved Gmail ' . esc_html( $label ) . ' is not valid JSON.' );
		}
		return $decoded;
	}
}

<?php
/**
 * Google OAuth client credentials loaded from client_secret.json (Web-application or Desktop-app).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model;

use RuntimeException;
use stdClass;

/**
 * Value object representing a Google OAuth client's credentials.
 *
 * Models the common subset of Google's `web` (Web-application) and `installed` (Desktop-app) client
 * shapes — the library uses the same fields for both; only the JSON envelope key differs.
 */
readonly class OAuth_Client_Credentials {

	/**
	 * Constructor.
	 *
	 * @param string   $client_id                     OAuth client ID.
	 * @param string   $project_id                    Google project ID.
	 * @param string   $auth_uri                      Authorization endpoint URI.
	 * @param string   $token_uri                     Token endpoint URI.
	 * @param string   $auth_provider_x509_cert_url   Certificate URL for the auth provider.
	 * @param string   $client_secret                 OAuth client secret.
	 * @param string[] $redirect_uris                 Allowed redirect URIs.
	 * @param string[] $javascript_origins            Allowed JavaScript origins (Web-application clients only).
	 */
	public function __construct(
		public string $client_id,
		public string $project_id,
		public string $auth_uri,
		public string $token_uri,
		public string $auth_provider_x509_cert_url,
		public string $client_secret,
		public array $redirect_uris = array(),
		public array $javascript_origins = array(),
	) {
	}

	/**
	 *
	 *
	 * Creates an instance from a JSON credentials file.
	 *
	 * @param string $file_path Path to the credentials JSON file.
	 *
	 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	 *
	 * @throws RuntimeException If the file cannot be read.
	 */
	public static function from_file( string $file_path ): OAuth_Client_Credentials {
		$json_string = file_get_contents( $file_path );
		if ( false === $json_string ) {
			throw new RuntimeException( 'Failed to read credentials file: ' . esc_html( $file_path ) );
		}
		$json = json_decode( $json_string );
		if ( null === $json ) {
			throw new RuntimeException( 'The credentials file did not contain valid JSON: ' . esc_html( $file_path ) );
		}
		return self::from_json( $json );
	}

	/**
	 * Rebuild from the flat, stored representation (the object's own properties, as written by
	 * {@see \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Json_Trait}),
	 * as opposed to Google's downloaded JSON with its `web`/`installed` wrapper ({@see from_json()}).
	 *
	 * @param array<mixed> $data The decoded JSON.
	 */
	public static function from_array( array $data ): OAuth_Client_Credentials {
		return new OAuth_Client_Credentials(
			self::string_at( $data, 'client_id' ),
			self::string_at( $data, 'project_id' ),
			self::string_at( $data, 'auth_uri' ),
			self::string_at( $data, 'token_uri' ),
			self::string_at( $data, 'auth_provider_x509_cert_url' ),
			self::string_at( $data, 'client_secret' ),
			self::strings_at( $data, 'redirect_uris' ),
			self::strings_at( $data, 'javascript_origins' ),
		);
	}

	/**
	 * A string field from the stored representation, or empty string.
	 *
	 * @param array<mixed> $data The decoded JSON.
	 * @param string       $key  The field.
	 */
	private static function string_at( array $data, string $key ): string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * A list of strings from the stored representation, dropping anything else.
	 *
	 * @param array<mixed> $data The decoded JSON.
	 * @param string       $key  The field.
	 *
	 * @return string[]
	 */
	private static function strings_at( array $data, string $key ): array {
		$value = $data[ $key ] ?? null;

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Creates an instance from a decoded JSON object.
	 *
	 * Google labels the client config `web` for Web-application clients and `installed` for Desktop-app
	 * clients — the latter is what you create for a CLI flow that has no callback URL. Both shapes are
	 * accepted; Desktop-app clients simply omit `javascript_origins`.
	 *
	 * @param stdClass $json Decoded JSON object from client_secret.json.
	 *
	 * @throws RuntimeException When neither a `web` nor an `installed` OAuth client is present.
	 */
	public static function from_json( stdClass $json ): OAuth_Client_Credentials {

		$client = $json->web ?? $json->installed ?? null;

		if ( ! $client instanceof stdClass ) {
			throw new RuntimeException( 'The credentials JSON did not contain a "web" or "installed" OAuth client.' );
		}

		return new OAuth_Client_Credentials(
			$client->client_id,
			$client->project_id,
			$client->auth_uri,
			$client->token_uri,
			$client->auth_provider_x509_cert_url,
			$client->client_secret,
			$client->redirect_uris ?? array(),
			$client->javascript_origins ?? array(),
		);
	}
}

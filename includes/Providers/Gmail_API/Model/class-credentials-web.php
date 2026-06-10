<?php
/**
 * Google OAuth web application credentials loaded from credentials.json.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model;

use stdClass;

/**
 * Value object representing Google OAuth web application credentials.
 */
readonly class Credentials_Web {

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
	 * @param string[] $javascript_origins            Allowed JavaScript origins.
	 */
	public function __construct(
		public string $client_id,
		public string $project_id,
		public string $auth_uri,
		public string $token_uri,
		public string $auth_provider_x509_cert_url,
		public string $client_secret,
		public array $redirect_uris,
		public array $javascript_origins,
	) {
	}

	/**
	 * Creates a Credentials_Web instance from a JSON credentials file.
	 *
	 * @param string $file_path Path to the credentials JSON file.
	 */
	public static function from_file( string $file_path ): Credentials_Web {
		$json_string = file_get_contents( $file_path );
		$json        = json_decode( $json_string );
		return self::from_json( $json );
	}

	/**
	 * Creates a Credentials_Web instance from a decoded JSON object.
	 *
	 * @param stdClass $json Decoded JSON object from credentials.json.
	 */
	public static function from_json( stdClass $json ): Credentials_Web {
		return new Credentials_Web(
			$json->web->client_id,
			$json->web->project_id,
			$json->web->auth_uri,
			$json->web->token_uri,
			$json->web->auth_provider_x509_cert_url,
			$json->web->client_secret,
			$json->web->redirect_uris,
			$json->web->javascript_origins
		);
	}
}

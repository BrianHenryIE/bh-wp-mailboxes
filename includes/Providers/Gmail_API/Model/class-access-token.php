<?php
/**
 * Google OAuth access token value object.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model;

use stdClass;

/**
 * Value object representing a Google OAuth access token.
 */
readonly class Access_Token {

	/**
	 * Constructor.
	 *
	 * @param string $access_token  The OAuth access token string.
	 * @param int    $expires_in    Seconds until the token expires.
	 * @param string $scope         OAuth scopes granted.
	 * @param string $token_type    Token type (usually "Bearer").
	 * @param int    $created       Unix timestamp when the token was created.
	 * @param string $refresh_token The refresh token for obtaining new access tokens.
	 */
	public function __construct(
		public string $access_token,
		public int $expires_in,
		public string $scope,
		public string $token_type,
		public int $created,
		public string $refresh_token,
	) {
	}

	/**
	 * Creates an Access_Token instance from a JSON token file.
	 *
	 * @param string $file_path Path to the access token JSON file.
	 */
	public static function from_file( string $file_path ): Access_Token {
		$json_string = file_get_contents( $file_path );
		if ( false === $json_string ) {
			throw new \RuntimeException( "Failed to read access token file: {$file_path}" );
		}
		$json = json_decode( $json_string );
		return self::from_json( $json );
	}

	/**
	 * Creates an Access_Token instance from a decoded JSON object.
	 *
	 * @param stdClass $json Decoded JSON object from the token file.
	 */
	public static function from_json( stdClass $json ): Access_Token {
		return new Access_Token(
			$json->access_token,
			$json->expires_in,
			$json->scope,
			$json->token_type,
			$json->created,
			$json->refresh_token
		);
	}
}

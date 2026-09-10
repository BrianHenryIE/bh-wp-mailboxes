<?php
/**
 * Gmail API credentials value object: the OAuth client and the account's access token.
 *
 * Built from the credentials store ({@see from_array()}), from Google's downloaded files
 * ({@see from_files()}), or after an OAuth exchange.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use InvalidArgumentException;
use RuntimeException;

/**
 * In-memory Gmail credentials, e.g. as loaded from the credentials store or built after an OAuth exchange.
 */
readonly class Gmail_Credentials implements Google_API_Credentials_Interface {

	use Google_API_Credentials_Json_Trait;

	/**
	 * The `type` key in the stored representation.
	 */
	const TYPE = 'gmail';

	/**
	 * Constructor.
	 *
	 * @param OAuth_Client_Credentials $project_credentials The Google Cloud OAuth client.
	 * @param ?Access_Token            $access_token        The account's access/refresh token, null until authorised.
	 */
	public function __construct(
		public OAuth_Client_Credentials $project_credentials,
		public ?Access_Token $access_token = null,
	) {
	}

	/**
	 * Read Google's downloaded OAuth client JSON and, when present, a saved access token JSON.
	 *
	 * Reads once; the resulting object is what the library saves to the credentials store.
	 *
	 * @param string $directory            The directory holding both files.
	 * @param string $client_secret_file   The OAuth client JSON as downloaded from Google Cloud Console (`web` or `installed`).
	 * @param string $access_token_file    The token JSON from the authorisation flow; optional, null token when absent.
	 *
	 * @throws RuntimeException When the client file is missing, or a file cannot be read or is not valid JSON.
	 */
	public static function from_files( string $directory, string $client_secret_file = 'client_secret.json', string $access_token_file = 'access_token.json' ): self {
		$client_secret_path = $directory . '/' . $client_secret_file;
		$access_token_path  = $directory . '/' . $access_token_file;

		if ( ! file_exists( $client_secret_path ) ) {
			throw new RuntimeException( 'OAuth client file not found: ' . esc_html( $client_secret_path ) );
		}

		return new self(
			OAuth_Client_Credentials::from_file( $client_secret_path ),
			file_exists( $access_token_path ) ? Access_Token::from_file( $access_token_path ) : null,
		);
	}

	/**
	 * Rebuild from the stored representation ({@see Google_API_Credentials_Json_Trait::jsonSerialize()}).
	 *
	 * @param array<mixed> $data The decoded JSON.
	 *
	 * @throws InvalidArgumentException When the record is not Gmail credentials or lacks the OAuth client.
	 */
	public static function from_array( array $data ): self {
		if ( self::TYPE !== ( $data['type'] ?? self::TYPE ) ) {
			throw new InvalidArgumentException( 'Not Gmail credentials.' );
		}

		$client = $data['client'] ?? null;
		if ( ! is_array( $client ) ) {
			throw new InvalidArgumentException( 'Gmail credentials are missing the OAuth client.' );
		}

		$access_token = $data['access_token'] ?? null;

		return new self(
			OAuth_Client_Credentials::from_array( $client ),
			is_array( $access_token ) ? Access_Token::from_json( (object) $access_token ) : null,
		);
	}

	/**
	 * The Google Cloud OAuth client.
	 */
	public function get_project_credentials(): OAuth_Client_Credentials {
		return $this->project_credentials;
	}

	/**
	 * The account's access token, or null when the account has not been authorised yet.
	 */
	public function get_access_token(): ?Access_Token {
		return $this->access_token;
	}
}

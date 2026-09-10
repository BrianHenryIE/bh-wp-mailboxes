<?php
/**
 * Gmail API credentials value object: the OAuth client and the account's access token.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;
use InvalidArgumentException;

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

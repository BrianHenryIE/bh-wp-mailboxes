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

/**
 * In-memory Gmail credentials, e.g. as loaded from the credentials store or built after an OAuth exchange.
 */
readonly class Gmail_Credentials implements Google_API_Credentials_Interface {

	/**
	 * Constructor.
	 *
	 * @param OAuth_Client_Credentials $project_credentials The Google Cloud OAuth client.
	 * @param ?Access_Token            $access_token        The account's access/refresh token, null until authorised.
	 */
	public function __construct(
		protected OAuth_Client_Credentials $project_credentials,
		protected ?Access_Token $access_token = null,
	) {
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

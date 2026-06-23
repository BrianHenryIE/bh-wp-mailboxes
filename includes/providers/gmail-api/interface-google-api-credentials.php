<?php
/**
 * Interface for Google API credentials.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\OAuth_Client_Credentials;

interface Google_API_Credentials_Interface extends Account_Credentials_Interface {

	/**
	 * Returns the OAuth project credentials.
	 */
	public function get_project_credentials(): OAuth_Client_Credentials;

	/**
	 * Returns the access token, or null if none is stored.
	 */
	public function get_access_token(): ?Access_Token;
}

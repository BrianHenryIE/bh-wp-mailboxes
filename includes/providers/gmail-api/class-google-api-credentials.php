<?php
/**
 * Google API credentials loaded from a filesystem directory.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Credentials_Web;

/**
 * Loads project credentials and access tokens from JSON files in a directory.
 */
class Google_API_Credentials implements Google_API_Credentials_Interface {

	/**
	 * Constructor.
	 *
	 * @param string $directory_path         Path to the directory containing credential files.
	 * @param string $credentials_filename   Filename for the project credentials JSON.
	 * @param string $access_token_filename  Filename for the access token JSON.
	 */
	public function __construct(
		protected string $directory_path,
		protected string $credentials_filename = 'credentials.json',
		protected string $access_token_filename = 'access_token.json',
	) {
	}

	/**
	 * Returns the project OAuth credentials from the credentials file.
	 */
	public function get_project_credentials(): Credentials_Web {
		return Credentials_Web::from_file( $this->directory_path . '/' . $this->credentials_filename );
	}

	/**
	 * Returns the access token from the token file, or null if none exists.
	 */
	public function get_access_token(): ?Access_Token {
		return Access_Token::from_file( $this->directory_path . '/' . $this->access_token_filename );
	}
}

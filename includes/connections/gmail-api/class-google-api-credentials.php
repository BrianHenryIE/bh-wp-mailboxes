<?php
/**
 * Google API credentials loaded from a filesystem directory.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\OAuth_Client_Credentials;

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
		protected string $credentials_filename = 'client_secret.json',
		protected string $access_token_filename = 'access_token.json',
	) {
	}

	/**
	 * Returns the project OAuth credentials from the credentials file.
	 */
	public function get_project_credentials(): OAuth_Client_Credentials {
		return OAuth_Client_Credentials::from_file( $this->directory_path . '/' . $this->credentials_filename );
	}

	/**
	 * Returns the access token from the token file, or null if the file does not exist yet.
	 *
	 * A missing file is expected before the first authorization completes; a present-but-unreadable or
	 * invalid file is still treated as an error by {@see Access_Token::from_file()}.
	 */
	public function get_access_token(): ?Access_Token {
		$access_token_path = $this->directory_path . '/' . $this->access_token_filename;
		if ( ! file_exists( $access_token_path ) ) {
			return null;
		}
		return Access_Token::from_file( $access_token_path );
	}
}

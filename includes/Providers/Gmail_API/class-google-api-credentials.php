<?php

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Credentials_Web;

class Google_API_Credentials implements Google_API_Credentials_Interface {

	public function __construct(
		protected string $directory_path,
		protected string $credentials_filename = 'credentials.json',
		protected string $access_token_filename = 'access_token.json',
	) {
	}

	public function get_project_credentials(): Credentials_Web {
		return Credentials_Web::from_file( $this->directory_path . '/' . $this->credentials_filename );
	}

	public function get_access_token(): ?Access_Token {
		return Access_Token::from_file( $this->directory_path . '/' . $this->access_token_filename );
	}
}

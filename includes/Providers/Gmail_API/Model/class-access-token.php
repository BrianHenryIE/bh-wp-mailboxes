<?php

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model;

use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
use stdClass;

readonly class Access_Token {
	public function __construct(
		public string $access_token,
		public int $expires_in,
		public string $scope,
		public string $token_type,
		public int $created,
		public string $refresh_token,
	) {
	}

	public static function from_file( string $file_path ): Access_Token {
		$json_string = file_get_contents( $file_path );
		$json        = json_decode( $json_string );
		return self::from_json( $json );
	}

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

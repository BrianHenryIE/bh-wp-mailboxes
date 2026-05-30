<?php

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model;

use stdClass;

readonly class Credentials_Web {
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


	public static function from_file( string $file_path ): Credentials_Web {
		$json_string = file_get_contents( $file_path );
		$json        = json_decode( $json_string );
		return self::from_json( $json );
	}

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

<?php
/**
 * Serialises Gmail API credentials for the credentials store through the interface's getters.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

/**
 * For any {@see Google_API_Credentials_Interface} implementation, including the file-backed one: the
 * client and token values are captured, not the file paths.
 */
trait Google_API_Credentials_Json_Trait {

	/**
	 * The stored representation; {@see Gmail_Credentials::from_array()} is its inverse.
	 *
	 * @return array{type: string, client: array<string, mixed>, access_token: ?array<string, mixed>}
	 */
	public function jsonSerialize(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- JsonSerializable's method name.
		$access_token = $this->get_access_token();

		return array(
			'type'         => Gmail_Credentials::TYPE,
			'client'       => get_object_vars( $this->get_project_credentials() ),
			'access_token' => is_null( $access_token ) ? null : get_object_vars( $access_token ),
		);
	}
}

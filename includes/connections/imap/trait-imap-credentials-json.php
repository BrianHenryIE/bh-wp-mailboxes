<?php
/**
 * Serialises IMAP credentials for the credentials store through the interface's getters.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

/**
 * For any {@see IMAP_Credentials_Interface} implementation, including ones that read from the
 * environment or files: the values are captured, not the configuration.
 */
trait IMAP_Credentials_Json_Trait {

	/**
	 * The stored representation; {@see Imap_Credentials::from_array()} is its inverse.
	 *
	 * @return array{type: string, server: string, username: string, password: string, encryption: string, validate_cert: bool}
	 */
	public function jsonSerialize(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- JsonSerializable's method name.
		return array(
			'type'          => Imap_Credentials::TYPE,
			'server'        => $this->get_email_imap_server(),
			'username'      => $this->get_email_account_username(),
			'password'      => $this->get_email_account_password(),
			'encryption'    => $this->get_encryption(),
			'validate_cert' => $this->should_validate_cert(),
		);
	}
}

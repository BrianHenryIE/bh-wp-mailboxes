<?php
/**
 * IMAP credentials value object.
 *
 * Carries the credentials between the accounts admin UI, the credentials store and the connection.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

use InvalidArgumentException;

/**
 * Server, username, password and encryption for one IMAP account.
 */
readonly class Imap_Credentials implements IMAP_Credentials_Interface {

	use IMAP_Credentials_Json_Trait;

	/**
	 * The `type` key in the stored representation.
	 */
	const TYPE = 'imap';

	/**
	 * Constructor.
	 *
	 * @param string $server     IMAP server hostname or IP, with optional `:port`.
	 * @param string $username   Login username, usually the email address.
	 * @param string $password   Login password.
	 * @param string $encryption `TLS`, `STARTTLS`, or empty string for none.
	 */
	public function __construct(
		public string $server,
		public string $username,
		public string $password,
		public string $encryption = 'TLS',
	) {
	}

	/**
	 * Rebuild from the stored representation ({@see IMAP_Credentials_Json_Trait::jsonSerialize()}).
	 *
	 * An empty `encryption` string means "none" and is kept; only a record with no `encryption` key
	 * at all gets the TLS default.
	 *
	 * @param array<mixed> $data The decoded JSON.
	 *
	 * @throws InvalidArgumentException When the record is not IMAP credentials.
	 */
	public static function from_array( array $data ): self {
		if ( self::TYPE !== ( $data['type'] ?? self::TYPE ) ) {
			throw new InvalidArgumentException( 'Not IMAP credentials.' );
		}

		$string = fn( string $key ): string => is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';

		return new self(
			$string( 'server' ),
			$string( 'username' ),
			$string( 'password' ),
			array_key_exists( 'encryption', $data ) ? $string( 'encryption' ) : 'TLS',
		);
	}

	/**
	 * IMAP server domain name or IP address with optional :port number.
	 */
	public function get_email_imap_server(): string {
		return $this->server;
	}

	/**
	 * IMAP username. Probably in the format username@example.org.
	 */
	public function get_email_account_username(): string {
		return $this->username;
	}

	/**
	 * Password for logging on to IMAP server.
	 */
	public function get_email_account_password(): string {
		return $this->password;
	}

	/**
	 * TLS, STARTTLS, '' empty string for none.
	 */
	public function get_encryption(): string {
		return $this->encryption;
	}
}

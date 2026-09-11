<?php
/**
 * IMAP credentials loaded from environment variables.
 *
 * Reads IMAP server, username, password, and encryption from $_ENV.
 * The env-var key names are customisable via the constructor parameters.
 *
 * @see https://github.com/vlucas/phpdotenv
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Imap;

/**
 * Reads IMAP credentials from PHP environment variables.
 */
class Imap_Credentials_Env implements IMAP_Credentials_Interface {

	use IMAP_Credentials_Json_Trait;

	/**
	 * Maps field names ('server', 'username', …) to the env-var key names they were loaded from.
	 *
	 * @var array<string,string>
	 */
	protected array $map = array();

	/**
	 * Whether to verify the server's TLS certificate, from the env var.
	 *
	 * @var bool
	 */
	protected bool $validate_cert = true;

	/**
	 * Constructor.
	 *
	 * @param string $server     Env-var key for the IMAP server hostname.
	 * @param string $username   Env-var key for the IMAP account username.
	 * @param string $password   Env-var key for the IMAP account password.
	 * @param string $encryption    Env-var key for the encryption type (TLS, STARTTLS, or empty for none).
	 * @param string $validate_cert Env-var key for whether to verify the server's certificate (`false`, `0`, `no` or `off` disable it; absent or anything else validates).
	 *
	 * @throws \Exception When a required credential (server, username, or password) is absent.
	 */
	public function __construct(
		protected string $server = 'IMAP_SERVER',
		protected string $username = 'IMAP_USERNAME',
		protected string $password = 'IMAP_PASSWORD',
		protected string $encryption = 'IMAP_ENCRYPTION',
		string $validate_cert = 'IMAP_VALIDATE_CERT',
	) {
		$this->map['server']        = $this->server;
		$this->map['username']      = $this->username;
		$this->map['password']      = $this->password;
		$this->map['encryption']    = $this->encryption;
		$this->map['validate_cert'] = $validate_cert;

		// Credentials are read verbatim from $_ENV; WordPress sanitization functions
		// (sanitize_text_field, strip_tags) would silently corrupt passwords that contain
		// '<', '>', '&', or other HTML-significant characters that are valid in credentials.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$this->server     = isset( $_ENV[ $this->server ] ) && is_string( $_ENV[ $this->server ] ) ? $_ENV[ $this->server ] : '';
		$this->username   = isset( $_ENV[ $this->username ] ) && is_string( $_ENV[ $this->username ] ) ? $_ENV[ $this->username ] : '';
		$this->password   = isset( $_ENV[ $this->password ] ) && is_string( $_ENV[ $this->password ] ) ? $_ENV[ $this->password ] : '';
		$this->encryption = isset( $_ENV[ $this->encryption ] ) && is_string( $_ENV[ $this->encryption ] ) ? $_ENV[ $this->encryption ] : '';
		// Only an explicit "false", "0", "no" or "off" turns validation off; absent or blank validates.
		$validate_cert_value = isset( $_ENV[ $validate_cert ] ) && is_string( $_ENV[ $validate_cert ] ) ? trim( $_ENV[ $validate_cert ] ) : '';
		$this->validate_cert = '' === $validate_cert_value || false !== filter_var( $validate_cert_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		$this->validate();
	}

	/**
	 * Throws when any required credential field is empty.
	 *
	 * @example Required IMAP credential server is missing. Please set environmental variables IMAP_SERVER.
	 * @example Required IMAP credentials server, username are missing. Please set environmental variables IMAP_SERVER, IMAP_USERNAME.
	 *
	 * @throws \Exception When server, username, or password is missing.
	 */
	protected function validate(): void {
		$missing = array();
		if ( empty( $this->server ) ) {
			$missing[] = 'server';
		}
		if ( empty( $this->username ) ) {
			$missing[] = 'username';
		}
		if ( empty( $this->password ) ) {
			$missing[] = 'password';
		}
		if ( empty( $missing ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message; not rendered to HTML.
		throw new \Exception(
			sprintf(
				'Required IMAP credential%s %s %s missing. Please set environmental variables %s.',
				count( $missing ) === 1 ? '' : 's',
				implode( ', ', $missing ),
				count( $missing ) === 1 ? 'is' : 'are',
				implode( ', ', array_map( fn( string $key ): string => $this->map[ $key ], $missing ) )
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Returns the IMAP server hostname.
	 */
	public function get_email_imap_server(): string {
		return $this->server;
	}

	/**
	 * Returns the IMAP account username.
	 */
	public function get_email_account_username(): string {
		return $this->username;
	}

	/**
	 * Returns the IMAP account password.
	 */
	public function get_email_account_password(): string {
		return $this->password;
	}

	/**
	 * Returns the encryption type (TLS, STARTTLS, or empty string for none).
	 */
	public function get_encryption(): string {
		return $this->encryption;
	}

	/**
	 * Whether to verify the server's TLS certificate (env var absent means yes).
	 */
	public function should_validate_cert(): bool {
		return $this->validate_cert;
	}
}

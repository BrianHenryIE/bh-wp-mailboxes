<?php
/**
 * IMAP credentials for the test mailbox, sourced from the dev settings page.
 *
 * Each field prefers its environment variable (loaded from test-credentials/.env.secret) and falls
 * back to a transient saved by the settings page — so the test mailbox can be configured in
 * WordPress Playground, where there is no .env.secret file.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface;

/**
 * IMAP credentials resolved from ENV (preferred) then transients.
 */
class Imap_Credentials_Settings implements IMAP_Credentials_Interface {

	public const TRANSIENT_SERVER     = 'bh_wp_mailboxes_dev_imap_server';
	public const TRANSIENT_USERNAME   = 'bh_wp_mailboxes_dev_imap_username';
	public const TRANSIENT_PASSWORD   = 'bh_wp_mailboxes_dev_imap_password';
	public const TRANSIENT_ENCRYPTION = 'bh_wp_mailboxes_dev_imap_encryption';

	/**
	 * Persist the IMAP credentials entered on the settings page (transients never expire here).
	 *
	 * @param string $server     IMAP server hostname (optionally with :port).
	 * @param string $username   IMAP account username.
	 * @param string $password   IMAP account password.
	 * @param string $encryption TLS, STARTTLS, or empty for none.
	 */
	public static function save( string $server, string $username, string $password, string $encryption ): void {
		set_transient( self::TRANSIENT_SERVER, $server, 0 );
		set_transient( self::TRANSIENT_USERNAME, $username, 0 );
		set_transient( self::TRANSIENT_PASSWORD, $password, 0 );
		set_transient( self::TRANSIENT_ENCRYPTION, $encryption, 0 );
	}

	/**
	 * True when server, username and password are all available (from ENV or transients).
	 */
	public function is_complete(): bool {
		return '' !== $this->get_email_imap_server()
			&& '' !== $this->get_email_account_username()
			&& '' !== $this->get_email_account_password();
	}

	/**
	 * Returns the IMAP server hostname.
	 */
	public function get_email_imap_server(): string {
		return $this->value( 'IMAP_SERVER', self::TRANSIENT_SERVER );
	}

	/**
	 * Returns the IMAP account username.
	 */
	public function get_email_account_username(): string {
		return $this->value( 'IMAP_USERNAME', self::TRANSIENT_USERNAME );
	}

	/**
	 * Returns the IMAP account password.
	 */
	public function get_email_account_password(): string {
		return $this->value( 'IMAP_PASSWORD', self::TRANSIENT_PASSWORD );
	}

	/**
	 * Returns the encryption type (TLS, STARTTLS, or empty string for none).
	 */
	public function get_encryption(): string {
		return $this->value( 'IMAP_ENCRYPTION', self::TRANSIENT_ENCRYPTION );
	}

	/**
	 * Resolve one field: the environment variable when set, otherwise the saved transient.
	 *
	 * @param string $env_key       The $_ENV key to prefer.
	 * @param string $transient_key The transient key to fall back to.
	 */
	private function value( string $env_key, string $transient_key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- loaded from dotenv, not request input.
		if ( isset( $_ENV[ $env_key ] ) && '' !== $_ENV[ $env_key ] ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- credentials are used verbatim.
			$env_value = $_ENV[ $env_key ];
			return ( ! empty( $env_value ) && is_string( $env_value ) ) ? $env_value : '';
		}

		$stored = get_transient( $transient_key );

		return is_string( $stored ) ? $stored : '';
	}
}

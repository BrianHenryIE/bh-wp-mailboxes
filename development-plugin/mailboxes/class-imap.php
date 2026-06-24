<?php
/**
 * An example IMAP mailbox using credentials in `test-credentials/.env.secret`.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\Imap_Credentials_Env;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use Dotenv\Dotenv;

/**
 * Provides IMAP mailbox settings loaded from test-credentials.
 */
class Imap {

	/**
	 * Returns true when the IMAP credentials file exists.
	 */
	public function is_credentials_present(): bool {
		return file_exists( '/var/www/test-credentials/.env.secret' );
	}

	/**
	 * Returns the IMAP mailbox settings, or null when credentials are absent.
	 */
	public function get_mailbox_settings(): ?Email_Account_Settings_Interface {

		if ( ! $this->is_credentials_present() ) {
			return null;
		}

		$dotenv = Dotenv::createImmutable( '/var/www/test-credentials/', '.env.secret', true );
		$dotenv->load();

		$imap_mailbox_settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			/**
			 * Returns the IMAP account email address.
			 */
			public function get_account_email_address(): string {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- loaded from dotenv file, not user input.
				return $_ENV['IMAP_USERNAME'] ?? '';
			}
		};

		return $imap_mailbox_settings;
	}

	/**
	 * Returns IMAP credentials loaded from environment variables.
	 */
	public function get_credentials(): Account_Credentials_Interface {
		return new Imap_Credentials_Env();
	}
}

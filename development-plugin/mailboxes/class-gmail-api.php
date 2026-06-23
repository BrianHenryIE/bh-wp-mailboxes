<?php
/**
 * Loads Google Developer Console project credentials from the `test-credentials` directory.
 *
 * /var/www/test-credentials/client_secret.json  (OAuth client; required to connect/authorize)
 * /var/www/test-credentials/access_token.json   (created by the first authorization)
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

/**
 * Provides Gmail API mailbox settings loaded from test-credentials.
 */
class Gmail_API {

	/**
	 * The directory holding the OAuth client secret and the (generated) access token.
	 */
	public const CREDENTIALS_DIRECTORY = '/var/www/test-credentials';

	/**
	 * The Gmail account to connect.
	 */
	public const ACCOUNT_EMAIL_ADDRESS = 'brianhenryie@gmail.com';

	/**
	 * True when the OAuth client secret is present — enough to create a connection and authorize.
	 */
	public function is_client_secret_present(): bool {
		return file_exists( self::CREDENTIALS_DIRECTORY . '/google_web_client_secret.json' );
	}

	/**
	 * True when both the client secret and a generated access token are present — ready to fetch.
	 */
	public function is_credentials_present(): bool {
		return $this->is_client_secret_present()
			&& file_exists( self::CREDENTIALS_DIRECTORY . '/access_token.json' );
	}

	/**
	 * The Gmail account email address.
	 */
	public function get_account_email_address(): string {
		return self::ACCOUNT_EMAIL_ADDRESS;
	}

	/**
	 * Returns the Gmail mailbox settings, or null when the client secret is absent.
	 *
	 * Gated on the client secret (not the access token) so the account can be connected and authorized
	 * before the first token exists.
	 */
	public function get_mailbox_settings(): ?Email_Account_Settings_Interface {

		if ( ! $this->is_client_secret_present() ) {
			return null;
		}

		return new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			/**
			 * Returns the Gmail account email address.
			 */
			public function get_account_email_address(): string {
				return Gmail_API::ACCOUNT_EMAIL_ADDRESS;
			}

			/**
			 * When false, the account is not checked on cron.
			 */
			public function is_active(): bool {
				return true;
			}
		};
	}

	/**
	 * Returns the Google API credentials, loaded from the test-credentials directory.
	 */
	public function get_credentials(): Account_Credentials_Interface {
		return new Google_API_Credentials(
			directory_path: self::CREDENTIALS_DIRECTORY,
			credentials_filename: 'google_desktop_client_secret.json',
		);
	}
}

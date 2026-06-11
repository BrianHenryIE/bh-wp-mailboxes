<?php
/**
 * Loads Google Developer Console project credentials from `test-credentials` directory.
 *
 * /var/www/test-credentials/credentials.json
 * /var/www/test-credentials/access_token.json
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
	 * Returns true when both required credential files exist.
	 */
	public function is_credentials_present(): bool {
		return file_exists( '/var/www/test-credentials/credentials.json' )
			&& file_exists( '/var/www/test-credentials/access_token.json' );
	}


	/**
	 * Returns the Gmail mailbox settings, or null when credentials are absent.
	 */
	public function get_mailbox_settings(): ?Email_Account_Settings_Interface {

		if ( ! $this->is_credentials_present() ) {
			return null;
		}

		$gmail_mailbox_settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			/**
			 * Returns the Gmail account email address.
			 */
			public function get_account_email_address(): string {
				return 'brianhenryie@gmail.com';
			}

			/**
			 * When false, the account is not checked on cron.
			 */
			public function is_active(): bool {
				return true;
			}
		};

		return $gmail_mailbox_settings;
	}

	/**
	 * Returns the Google API credentials.
	 */
	public function get_credentials(): Account_Credentials_Interface {
		return new Google_API_Credentials( __DIR__ );
	}
}

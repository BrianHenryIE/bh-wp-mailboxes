<?php
/**
 * Loads Google Developer Console project credentials from `test-credentials` directory.
 *
 * /var/www/test-credentials/credentials.json
 * /var/www/test-credentials/access_token.json
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;

class Gmail_API {

	public function get_mailbox_settings(): ?Mailbox_Settings_Interface {
		if ( ! file_exists( '/var/www/test-credentials/credentials.json', ) ) {
			return null;
		}

		$gmail_mailbox_settings = new class() implements Mailbox_Settings_Interface {
			use Mailbox_Settings_Defaults_Trait;

			public function get_account_unique_friendly_name(): string {
				return 'brianhenryie@gmail.com';
			}

			public function get_credentials(): Account_Credentials_Interface {
				return new Google_API_Credentials( __DIR__ );
			}
		};

		return $gmail_mailbox_settings;
	}
}

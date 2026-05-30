<?php
/**
 * An example IMAP mailbox using credentials in `test-credentials/.env.secret`.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use Dotenv\Dotenv;

class Imap {

	public function is_credentials_present(): bool {
		return file_exists( '/var/www/test-credentials/.env.secret' );
	}

	public function get_mailbox_settings(): ?Mailbox_Settings_Interface {

		if ( ! $this->is_credentials_present() ) {
			return null;
		}

		$dotenv = Dotenv::createImmutable( '/var/www/test-credentials/', '.env.secret', true );
		$dotenv->load();

		$imap_mailbox_settings = new class() implements Mailbox_Settings_Interface {
			use Mailbox_Settings_Defaults_Trait;

			public function get_account_unique_friendly_name(): string {
				return 'support@brianhenryie.com';
			}

			public function get_credentials(): Account_Credentials_Interface {
				return new class() implements IMAP_Credentials_Interface {
					public function get_email_imap_server(): string {
						return $_ENV['IMAP_SERVER'];
					}

					public function get_email_account_username(): string {
						return $_ENV['IMAP_USERNAME'];
					}

					public function get_email_account_password(): string {
						return $_ENV['IMAP_PASSWORD'];
					}

					public function get_encryption(): string {
						return '';
					}
				};
			}
		};

		return $imap_mailbox_settings;
	}
}

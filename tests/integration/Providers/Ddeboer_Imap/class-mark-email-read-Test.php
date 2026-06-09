<?php
/**
 * Tests Email_Fetcher with a real server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use Psr\Log\NullLogger;

class Mark_Email_Read_Test extends \Codeception\TestCase\WPTestCase {

	/**
	 * Dumps the emails from the past [time] into /tests/_data/emails
	 */
	public function test_after_reconcile_emails_for_tests() {

		$this->markTestIncomplete();

		$time = HOUR_IN_SECONDS * 3;

		/** @var Email_Account_Settings_Interface $settings */
		$settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

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

				};
			}
		};

		$logger = new NullLogger();

		$cpt = '';

		$sut = new ImapEngine_Imap_Email_Fetcher( $settings, $logger );

		$since_time = new \DateTime()->modify( "-{$time} seconds" );

		$emails = $sut->retrieve_emails( $since_time );

		foreach ( $emails as $email ) {

			$b = $email->get_body();

			$email->after_reconcile();

		}
	}
}

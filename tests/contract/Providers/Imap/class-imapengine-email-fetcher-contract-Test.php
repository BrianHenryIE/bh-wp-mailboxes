<?php
/**
 * Tests Email_Fetcher with a real server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Emails\API\ImapEngine_Imap;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use DateTime;
use DirectoryTree\ImapEngine\Exceptions\Exception as ImapEngineException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use Dotenv\Dotenv;

/**
 * Username and password are read from `.env.secrets`.
 */
class ImapEngine_Email_Fetcher_Integration_Test extends Unit_Testcase {

	protected Mailbox_Settings_Interface $settings;

	public function setUp(): void {
		parent::setUp();

		global $project_root_dir;

		if ( ! file_exists( $project_root_dir . '/test-credentials/.env.secret' ) ) {
			$this->fail( 'Please configure: test-credentials/.env.secret' );
		}
		$dotenv = Dotenv::createImmutable( $project_root_dir . '/test-credentials/', '.env.secret', true );
		$dotenv->load();

		/** @var Mailbox_Settings_Interface $settings */
		$this->settings = new class() implements Mailbox_Settings_Interface {

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
						return $_ENV['IMAP_ENCRYPTION'];
					}
				};
			}
		};
	}

	/**
	 * Dumps the emails from the past [time] into /tests/_data/emails
	 */
	public function test_download_emails_for_tests() {

		try {
			$sut = new ImapEngine_Imap_Email_Fetcher( $this->settings, $this->logger );
		} catch ( ImapEngineException $e ) {
			// * When the server or user/password are bad.
			// * DirectoryTree\ImapEngine\Exceptions\ImapStreamException : Unexpected end of stream while trying to fill the buffer
			$this->fail( $e->getMessage() );
		}

		$year_in_seconds = 365 * 24 * 60 * 60;
		$since_unix_time = time() - $year_in_seconds;
		$since_time      = DateTime::createFromFormat( 'U', $since_unix_time );
		$emails          = $sut->retrieve_emails( $since_time );

		$this->assertNotEmpty( $emails->count() );
	}

	public function test_bad_server(): void {

		$logger = new ColorLogger();

		/** @var Mailbox_Settings_Interface $settings */
		$settings = new class() implements Mailbox_Settings_Interface {

			use Mailbox_Settings_Defaults_Trait;

			public function get_account_unique_friendly_name(): string {
				return 'support@brianhenryie.com';
			}

			public function get_credentials(): Account_Credentials_Interface {
				return new class() implements IMAP_Credentials_Interface {
					public function get_email_imap_server(): string {
						return 'imap.example.com';
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

		try {
			$imap = new ImapEngine_Imap_Email_Fetcher( $settings, $logger );
			$imap->retrieve_emails( DateTime::createFromFormat( 'U', time() - YEAR_IN_SECONDS ) );
		} catch ( ImapConnectionFailedException $e ) {
			$exception = $e;
		} catch ( ImapEngineException $e ) {
			$exception = $e;
		}

		$this->assertNotEmpty( $exception );
	}

	/**
	 * Test to find when response is given when the password is incorrect.
	 */
	public function test_bad_password(): void {

		$logger = new ColorLogger();

		/** @var Mailbox_Settings_Interface $settings */
		$settings = new class() implements Mailbox_Settings_Interface {

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
						return 'bad-password';
					}

					public function get_encryption(): string {
						return '';
					}
				};
			}
		};

		try {
			$fetcher = new ImapEngine_Imap_Email_Fetcher( $settings, $logger );
		} catch ( ImapCommandException $e ) {
			$imapCommandException = $e;
		} catch ( ImapEngineException $e ) {
			$imapEngineException = $e;
		}

		throw $e;
		// $this->assertNotEmpty( $exception );
	}
}

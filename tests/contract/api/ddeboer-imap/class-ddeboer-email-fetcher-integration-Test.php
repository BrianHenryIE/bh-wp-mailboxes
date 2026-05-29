<?php
/**
 * Tests Email_Fetcher with a real server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Emails\API\Ddeboer_Imap;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap\Ddeboer_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\API\Ddeboer_Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTime;

/**
 * Username and password are read from `.env.secrets`.
 */
class Ddeboer_Email_Fetcher_Integration_Test extends \Codeception\TestCase\WPTestCase {

	protected Mailbox_Settings_Interface $settings;

	/**
	 * Register the cpt, category type, and mailbox category
	 */
	public function setUp(): void {
		parent::setUp();

		$logger   = new ColorLogger();
		$mailbox  = $this->makeEmpty(
			Mailbox_Settings_Interface::class,
			array(
				'get_account_unique_friendly_name' => 'support@brianhenryie.com',
			)
		);
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_friendly_name' => 'Email Test CPT',
				'get_mailboxes'         => array( $mailbox ),
			)
		);

		$bh_email_cpt = new BH_Email_CPT( $settings, $logger );
		$bh_email_cpt->register_cpt();
		$bh_email_cpt->register_mailboxes_taxonomy();
		$bh_email_cpt->register_mailbox();

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
				};
			}
		};
	}

	/**
	 * Dumps the emails from the past [time] into /tests/_data/emails
	 */
	public function test_download_emails_for_tests() {

		$this->markTestIncomplete();

		$logger = new ColorLogger();
		$cpt    = 'email_test_cpt';

		try {
			$sut = new Ddeboer_Imap_Email_Fetcher( $cpt, $this->settings, $logger );
		} catch ( \Ddeboer\Imap\Exception\AuthenticationFailedException $e ) {
			// When the server or user/password are bad.
			$exception = $e;
		}

		$since_unix_time = time() - YEAR_IN_SECONDS;
		$since_time      = DateTime::createFromFormat( 'U', $since_unix_time );
		$emails          = $sut->retrieve_emails( $since_time );

		foreach ( $emails as $email ) {
			$post_id = $email->save();

		}

		$a_post = get_post( $post_id );

		$args  = array( 'post_type' => 'email_test_cpt' );
		$query = new \WP_Query( $args );
		$posts = $query->get_posts();
		foreach ( $posts as $post ) {
			$a = $post->post_title;
		}
	}


	public function test_bad_server(): void {

		$exception_message = <<<'EOD'
[E_WARNING] Authentication failed for user "supportbrianhenryiecom@brianhenryie.com": imap_open(): Couldn't open stream {imap.example.com/imap/ssl/novalidate-cert}
imap_alerts (0):
imap_errors (1):
- No such host as imap.example.com
EOD;

		$logger = new ColorLogger();
		$cpt    = 'email_test_cpt';

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
				};
			}
		};

		try {
			new Ddeboer_Imap_Email_Fetcher( $cpt, $settings, $logger );
		} catch ( \Ddeboer\Imap\Exception\AuthenticationFailedException $e ) {
			$exception = $e;
		}

		$this->assertNotEmpty( $exception );
		$this->assertEquals( $exception_message, $exception->getMessage() );
	}

	/**
	 * Test to find when response is given when the password is incorrect.
	 */
	public function test_bad_password(): void {

		$exception_message = <<<'EOD'
[E_WARNING] Authentication failed for user "supportbrianhenryiecom@brianhenryie.com": imap_open(): Couldn't open stream {epsilon.hostineer.com/imap/ssl/novalidate-cert}
imap_alerts (0):
imap_errors (1):
- Can not authenticate to IMAP server: [AUTHENTICATIONFAILED] Authentication failed.
EOD;

		$logger = new ColorLogger();
		$cpt    = 'email_test_cpt';

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
				};
			}
		};

		try {
			new Ddeboer_Imap_Email_Fetcher( $cpt, $settings, $logger );
		} catch ( \Ddeboer\Imap\Exception\AuthenticationFailedException $e ) {
			$exception = $e;
		}

		$this->assertNotEmpty( $exception );
		$this->assertEquals( $exception_message, $exception->getMessage() );
	}
}

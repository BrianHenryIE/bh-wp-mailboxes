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
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateTime;
use DirectoryTree\ImapEngine\Exceptions\Exception as ImapEngineException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use Dotenv\Dotenv;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Username and password are read from `.env.secrets`.
 */
class ImapEngine_Email_Fetcher_Integration_Test extends Unit_Testcase {

	protected Email_Account_Settings_Interface $settings;

	public function setUp(): void {
		parent::setUp();

		global $project_root_dir;

		if ( ! file_exists( $project_root_dir . '/test-credentials/.env.secret' ) ) {
			$this->fail( 'Please configure: test-credentials/.env.secret' );
		}
		$dotenv = Dotenv::createImmutable( $project_root_dir . '/test-credentials/', '.env.secret', true );
		$dotenv->load();

		/** @var Email_Account_Settings_Interface $settings */
		$this->settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			public function get_account_email_address(): string {
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
	 * Tool to create fixtures.
	 */
	protected function save_to_file( IMessage $email, string $filepath, bool $include_attachments = true ): void {
		$attachment_parts      = $email->getAllAttachmentParts();
		$all_parts             = $email->getAllParts();
		$non_attachment_parts  = array_filter(
			$all_parts,
			fn( $part ) => ! in_array( $part, $attachment_parts, true )
		);
		$original_email_string = $include_attachments
			? implode( ' ', $all_parts )
			: implode( ' ', $non_attachment_parts );

		file_put_contents( $filepath, $original_email_string );

		$from_file = file_get_contents( $filepath );

		$parser           = new MailMimeParser();
		$reparsed         = $parser->parse( $from_file, true );
		$saved_message_id = $reparsed->getMessageId();
		$this->assertNotEmpty( $saved_message_id );
	}

	/**
	 * Mark the most-recently fetched email as read on the server, then unread.
	 *
	 * Ported from the removed Ddeboer_Imap integration test (tests/integration/Providers/Ddeboer_Imap/class-mark-email-read-Test.php).
	 * The Ddeboer provider is gone; ImapEngine's Message object exposes flag() / unflag() for this purpose.
	 */
	public function test_mark_email_read_on_server(): void {

		try {
			$sut = new ImapEngine_Imap_Email_Fetcher( $this->settings, $this->logger );
		} catch ( ImapEngineException $e ) {
			$this->fail( $e->getMessage() );
		}

		$since_time = DateTime::createFromFormat( 'U', (string) ( time() - 30 * 24 * 60 * 60 ) );
		$messages   = $sut->retrieve_emails( $since_time, 1 );

		if ( $messages->isEmpty() ) {
			$this->markTestSkipped( 'No emails found in inbox to test mark-read.' );
		}

		// The IMessage returned by retrieve_emails() is a parsed snapshot; to mark read on the
		// server we need the underlying ImapEngine Message. That requires accessing the mailbox
		// directly — this test serves as a living specification for the work tracked in Phase 2.
		$this->markTestIncomplete( 'ImapEngine_Imap_Email_Fetcher does not yet expose a mark-read method. Implement per Phase 2.' );
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

		$year_in_seconds = 367 * 24 * 60 * 60;
		$since_unix_time = time() - $year_in_seconds;
		$since_time      = DateTime::createFromFormat( 'U', $since_unix_time );
		$messages        = $sut->retrieve_emails( $since_time, 100 );

		$this->assertNotEmpty( $messages->count() );

		// Don't accidentally save anything.
		return;

		/** @var IMessage $email */
		foreach ( $messages as $email ) {
			$sender_address = $email->getHeader( 'From' )->getEmail();
			if ( 'contact@bhwp.ie' === $sender_address ) {
				// We only get WordFence and Comment emails from the site, the interesting ones come from other senders.
				continue;
			}

			$this->save_to_file(
				$email,
				codecept_root_dir() . 'tests/_data/temp/' . base64_encode( $email->getHeaderValue( 'Message-ID' ) ) . '.eml',
			);
		}
	}


	public function test_bad_server(): void {

		$logger = new ColorLogger();

		/** @var Email_Account_Settings_Interface $settings */
		$settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			public function get_account_email_address(): string {
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

		/** @var Email_Account_Settings_Interface $settings */
		$settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			public function get_account_email_address(): string {
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

<?php
/**
 * Tests Email_Fetcher with a real server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 * @author     BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Emails\API\ImapEngine_Imap;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\Imap_Credentials_Env;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use DateInterval;
use DateTime;
use DirectoryTree\ImapEngine\Mailbox;
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
		};
	}

	/**
	 * Tool to create fixtures.
	 *
	 * @param IMessage $email Email to save.
	 * @param string   $filepath Absolute filepath to save to.
	 * @param bool     $include_attachments Should the email attachments remain or be removed.
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
	 * Live round-trip: set_is_marked_read() must flip the server `\Seen` flag both ways, as observed
	 * through get_is_marked_read(). The newest inbox message's original state is restored at the end.
	 *
	 * Requires live credentials in test-credentials/.env.secret; skipped when the inbox is empty.
	 */
	public function test_mark_email_read_on_server(): void {

		$credentials = new Imap_Credentials_Env();

		$sut = new ImapEngine_Imap_Email_Fetcher( $this->settings, $this->logger );
		$sut->set_credentials( $credentials );

		$since_time = ( new DateTime() )->sub( new DateInterval( 'P30D' ) );
		$newest     = $sut->retrieve_emails( $since_time, 1 )->first();

		if ( is_null( $newest ) ) {
			$this->markTestSkipped( 'No recent emails in inbox to test mark-read.' );
		}

		$coordinates   = $newest->coordinates;
		$original_seen = $sut->get_is_marked_read( $coordinates );

		try {
			$sut->set_is_marked_read( $coordinates, true );
			$this->assertTrue(
				$sut->get_is_marked_read( $coordinates ),
				'After set_is_marked_read(true) the message should be `\Seen`.'
			);

			$sut->set_is_marked_read( $coordinates, false );
			$this->assertFalse(
				$sut->get_is_marked_read( $coordinates ),
				'After set_is_marked_read(false) the message should not be `\Seen`.'
			);
		} finally {
			// Restore the message to the state we found it in.
			$sut->set_is_marked_read( $coordinates, $original_seen );
		}
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

		/** @var \BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email $fetched */
		foreach ( $messages as $fetched ) {
			$email          = $fetched->message;
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


	/**
	 * Live contract: get_is_marked_read() must agree with the server's actual `\Seen` flag along
	 * both lookup paths.
	 *
	 * Ground truth is read directly from ImapEngine (an independent oracle): the newest inbox
	 * message's UID, UIDVALIDITY, Message-ID, and `\Seen` flag. The fetcher is then asked via:
	 *  - the **UID path** — correct UID + UIDVALIDITY → direct FETCH;
	 *  - the **fallback path** — a deliberately wrong UIDVALIDITY forces the `Message-ID` search;
	 *  - the **not-found path** — bogus coordinates → false.
	 *
	 * Requires live credentials in test-credentials/.env.secret; skipped when the inbox is empty.
	 */
	public function test_get_is_marked_read_matches_server_flag(): void {

		$credentials = new Imap_Credentials_Env();

		// Independent oracle: read the newest recent inbox message + flag straight from the library.
		$oracle_inbox = $this->make_oracle_mailbox( $credentials )->inbox();
		$oracle_query = $oracle_inbox->messages();
		$oracle_query->since( ( new DateTime() )->sub( new DateInterval( 'P30D' ) ) );
		$newest = $oracle_query->withFlags()->get()->last();

		if ( is_null( $newest ) ) {
			$this->markTestSkipped( 'No recent emails in inbox to read read/unread status from.' );
		}

		$message_id    = $newest->messageId( true );
		$expected_seen = $newest->isSeen();
		$folder        = $oracle_inbox->path();
		$status        = $oracle_inbox->status();
		$uid_validity  = isset( $status['UIDVALIDITY'] ) ? (int) $status['UIDVALIDITY'] : null;
		$this->assertNotEmpty( $message_id, 'Newest inbox message has no Message-ID header.' );

		// System under test.
		$sut = new ImapEngine_Imap_Email_Fetcher( $this->settings, $this->logger );
		$sut->set_credentials( $credentials );

		// UID path: correct UID + UIDVALIDITY → direct FETCH.
		$uid_coordinates = new Remote_Email_Coordinates(
			message_id: $message_id,
			remote_uid: (string) $newest->uid(),
			folder: $folder,
			uid_validity: $uid_validity,
		);
		$this->assertSame(
			$expected_seen,
			$sut->get_is_marked_read( $uid_coordinates ),
			'UID path: should match the server `\Seen` flag.'
		);

		// Fallback path: a wrong UIDVALIDITY voids the UID, forcing the Message-ID search.
		$fallback_coordinates = new Remote_Email_Coordinates(
			message_id: $message_id,
			remote_uid: (string) $newest->uid(),
			folder: $folder,
			uid_validity: ( $uid_validity ?? 0 ) + 1,
		);
		$this->assertSame(
			$expected_seen,
			$sut->get_is_marked_read( $fallback_coordinates ),
			'Fallback path: a stale UIDVALIDITY should still resolve via the Message-ID search.'
		);

		// Not-found path: bogus Message-ID and no UID → false.
		$this->assertFalse(
			$sut->get_is_marked_read(
				new Remote_Email_Coordinates( message_id: 'missing-' . uniqid() . '@contract.invalid' )
			),
			'Unknown coordinates should report false.'
		);
	}

	/**
	 * Build a Mailbox connection directly from env credentials, mirroring the fetcher's own
	 * connection settings, to serve as an independent oracle for the contract test above.
	 *
	 * @param IMAP_Credentials_Interface $credentials Live IMAP credentials from the environment.
	 */
	private function make_oracle_mailbox( IMAP_Credentials_Interface $credentials ): Mailbox {
		$server = $credentials->get_email_imap_server();
		$host   = $server;
		if ( str_contains( $server, ':' ) ) {
			[ $host ] = explode( ':', $server, 2 );
		}

		return Mailbox::make(
			array(
				'host'          => $host,
				'username'      => $credentials->get_email_account_username(),
				'password'      => $credentials->get_email_account_password(),
				'encryption'    => 'tls',
				'validate_cert' => false,
			)
		);
	}

	public function test_bad_server(): void {

		$logger = new ColorLogger();

		/** @var Email_Account_Settings_Interface $settings */
		$settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			public function get_account_email_address(): string {
				return 'support@brianhenryie.com';
			}
		};

		$credentials = new Imap_Credentials_Env();

		try {
			$imap = new ImapEngine_Imap_Email_Fetcher( $settings, $logger );
			$imap->set_credentials( $credentials );
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

		$credentials = new Imap_Credentials_Env();

		/** @var Email_Account_Settings_Interface $settings */
		$settings = new class() implements Email_Account_Settings_Interface {
			use Email_Account_Settings_Defaults_Trait;

			public function get_account_email_address(): string {
				return 'support@brianhenryie.com';
			}

			public function is_active(): bool {
				return true;
			}
		};

		$exception = null;
		try {
			$fetcher = new ImapEngine_Imap_Email_Fetcher( $settings, $logger );
			$fetcher->set_credentials( $credentials );
		} catch ( ImapCommandException $exception ) {
			$imap_command_exception = $exception;
		} catch ( ImapEngineException $exception ) {
			$imap_engine_exception = $exception;
		}

		$this->assertNotEmpty( $exception );
	}
}

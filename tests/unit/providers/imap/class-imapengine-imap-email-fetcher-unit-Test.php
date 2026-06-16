<?php
/**
 * Unit tests for the ImapEngine IMAP fetcher's message→Fetched_Email mapping.
 *
 * The ImapEngine Mailbox is mocked (injected via reflection) so the fetch/parse mapping can be
 * exercised against the sanitized `.eml` fixtures without a live IMAP server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Carbon\Carbon;
use DateTime;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQuery;
use Mockery;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Provider
 */
class ImapEngine_Imap_Email_Fetcher_Unit_Test extends Unit_Testcase {

	/**
	 * Build the fetcher with a mocked Mailbox whose inbox returns the given messages from `get()`.
	 *
	 * @param MessageInterface[] $messages     The messages the (mocked) inbox query returns.
	 * @param int                $uid_validity The folder UIDVALIDITY to report.
	 * @param string             $folder_path  The folder path to report.
	 */
	private function make_sut_with_messages( array $messages, int $uid_validity = 12345, string $folder_path = 'INBOX' ): ImapEngine_Imap_Email_Provider {

		$query = Mockery::mock( MessageQuery::class );
		$query->allows( 'since' );
		$query->allows( 'limit' )->andReturnSelf();
		$query->allows( 'withHeaders' )->andReturnSelf();
		$query->allows( 'withBody' )->andReturnSelf();
		$query->allows( 'withFlags' )->andReturnSelf();
		$query->allows( 'get' )->andReturn( new MessageCollection( $messages ) );

		$folder = Mockery::mock( Folder::class );
		$folder->allows( 'path' )->andReturn( $folder_path );
		$folder->allows( 'status' )->andReturn( array( 'UIDVALIDITY' => $uid_validity ) );
		$folder->allows( 'messages' )->andReturn( $query );

		$mailbox = Mockery::mock( Mailbox::class );
		$mailbox->allows( 'inbox' )->andReturn( $folder );

		$settings = Mockery::mock( Email_Account_Settings_Interface::class );
		$sut      = new ImapEngine_Imap_Email_Provider( $settings, $this->logger );

		$property = new \ReflectionProperty( ImapEngine_Imap_Email_Provider::class, 'mailbox' );
		PHP_VERSION_ID < 80100 && $property->setAccessible( true );
		$property->setValue( $sut, $mailbox );

		return $sut;
	}

	/**
	 * Build a mocked ImapEngine message whose parse() returns the given fixture parsed.
	 *
	 * @param string $fixture_filename The `.eml` fixture under tests/_data/wpunit/.
	 * @param int    $uid              The UID to report.
	 * @param bool   $is_seen          The `\Seen` flag to report.
	 * @param Carbon $date             The message date (used by the since-time filter).
	 */
	private function make_message( string $fixture_filename, int $uid, bool $is_seen, Carbon $date ): MessageInterface {
		$imessage = ( new MailMimeParser() )->parse(
			(string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/' . $fixture_filename ) ),
			true
		);

		$message = Mockery::mock( MessageInterface::class );
		$message->allows( 'date' )->andReturn( $date );
		$message->allows( 'uid' )->andReturn( $uid );
		$message->allows( 'isSeen' )->andReturn( $is_seen );
		$message->allows( 'parse' )->andReturn( $imessage );

		return $message;
	}

	/**
	 * Each fetched message is mapped to a Fetched_Email carrying the parsed message, the IMAP
	 * coordinates (UID, folder, UIDVALIDITY, Message-ID) and the server read state.
	 *
	 * @covers ::__construct
	 * @covers ::retrieve_emails
	 */
	public function test_retrieve_emails_maps_messages_to_fetched_emails(): void {

		$now = Carbon::now();

		$messages = array(
			$this->make_message( 'test_save_new.eml', 11, true, $now ),
			$this->make_message( 'html-and-plaintext.eml', 22, false, $now ),
			$this->make_message( 'html-no-plain-text.eml', 33, true, $now ),
			$this->make_message( 'non-multipart.eml', 44, false, $now ),
		);

		$sut = $this->make_sut_with_messages( $messages, uid_validity: 999, folder_path: 'INBOX' );

		$result = $sut->retrieve_emails( ( new DateTime() )->modify( '-7 days' ), 100 );

		$fetched = $result->values()->all();
		$this->assertCount( 4, $fetched );

		// First message: read Wordfence alert.
		$first = $fetched[0];
		$this->assertInstanceOf( Fetched_Email::class, $first );
		$this->assertSame( '[Wordfence Alert] Problems found on bhwp.ie', $first->message->getSubject() );
		$this->assertTrue( $first->is_remote_read );
		$this->assertSame( '11', $first->coordinates->remote_uid );
		$this->assertSame( 'INBOX', $first->coordinates->folder );
		$this->assertSame( 999, $first->coordinates->uid_validity );
		$this->assertSame( $first->message->getMessageId(), $first->coordinates->message_id );

		// Second message: unread upgrade offer.
		$second = $fetched[1];
		$this->assertSame( 'Re: www.bhwp.ie - Your Website Ready for an Upgrade?', $second->message->getSubject() );
		$this->assertFalse( $second->is_remote_read );
		$this->assertSame( '22', $second->coordinates->remote_uid );

		// Remaining subjects map through in order.
		$this->assertSame( 'DMARC weekly digest for bhwp.ie', $fetched[2]->message->getSubject() );
		$this->assertSame( '44', $fetched[3]->coordinates->remote_uid );
	}

	/**
	 * IMAP `SINCE` only filters by date; messages older than the exact since-time are dropped here.
	 *
	 * @covers ::retrieve_emails
	 */
	public function test_retrieve_emails_filters_messages_before_since_time(): void {

		$messages = array(
			$this->make_message( 'test_save_new.eml', 11, true, Carbon::now() ),
			$this->make_message( 'html-and-plaintext.eml', 22, false, Carbon::now()->subYears( 2 ) ),
		);

		$sut = $this->make_sut_with_messages( $messages );

		$result = $sut->retrieve_emails( ( new DateTime() )->modify( '-7 days' ), 100 );

		$fetched = $result->values()->all();
		$this->assertCount( 1, $fetched );
		$this->assertSame( '11', $fetched[0]->coordinates->remote_uid );
	}

	/**
	 * Build the fetcher with a given mocked Mailbox injected.
	 *
	 * @param Mailbox $mailbox The mocked mailbox.
	 */
	private function make_sut_with_mailbox( Mailbox $mailbox ): ImapEngine_Imap_Email_Provider {
		$sut = new ImapEngine_Imap_Email_Provider( Mockery::mock( Email_Account_Settings_Interface::class ), $this->logger );

		$property = new \ReflectionProperty( ImapEngine_Imap_Email_Provider::class, 'mailbox' );
		PHP_VERSION_ID < 80100 && $property->setAccessible( true );
		$property->setValue( $sut, $mailbox );

		return $sut;
	}

	/**
	 * Connecting the mailbox returns true on success.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_connects_and_returns_true(): void {
		$mailbox = Mockery::mock( Mailbox::class );
		$mailbox->expects( 'connect' )->once();

		$this->assertTrue( $this->make_sut_with_mailbox( $mailbox )->test_connection() );
	}

	/**
	 * A connection failure propagates from test_connection().
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_rethrows_on_failure(): void {
		$mailbox = Mockery::mock( Mailbox::class );
		$mailbox->allows( 'connect' )->andThrow( new \RuntimeException( 'AUTHENTICATIONFAILED' ) );

		$this->expectException( \RuntimeException::class );
		$this->make_sut_with_mailbox( $mailbox )->test_connection();
	}
}

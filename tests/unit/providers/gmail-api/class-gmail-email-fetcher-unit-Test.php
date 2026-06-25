<?php
/**
 * Unit tests for the Gmail fetcher's message→Fetched_Email mapping.
 *
 * The Gmail service is mocked (via the `get_gmail_service()` seam) so the list/get/parse mapping can
 * be exercised against the sanitized `.eml` fixtures without live Google credentials.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTime;
use Google\Service\Gmail\ListMessagesResponse;
use Google\Service\Gmail\Message as Gmail_Message;
use Google\Service\Gmail\Profile;
use Google\Service\Gmail\Resource\Users;
use Google\Service\Gmail\Resource\UsersMessages;
use Google_Service_Gmail;
use Mockery;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Email_Connection
 */
class Gmail_Email_Fetcher_Unit_Test extends Unit_Testcase {

	/**
	 * Build the fetcher with its `get_gmail_service()` overridden to return a mock service whose
	 * `users_messages` resource lists the given full messages and returns them by id.
	 *
	 * @param Gmail_Message[] $full_messages The full (format=raw) messages, keyed by Gmail id.
	 */
	private function make_sut_listing( array $full_messages ): Gmail_Email_Connection {

		$list_response = new ListMessagesResponse();
		$list_items    = array();
		foreach ( array_keys( $full_messages ) as $id ) {
			$item = new Gmail_Message();
			$item->setId( (string) $id );
			$list_items[] = $item;
		}
		$list_response->setMessages( $list_items );

		$users_messages = Mockery::mock( UsersMessages::class );
		$users_messages->allows( 'listUsersMessages' )->andReturn( $list_response );
		$users_messages->allows( 'get' )->andReturnUsing(
			fn( string $user, string $id, array $opts ): Gmail_Message => $full_messages[ $id ]
		);

		$service                 = Mockery::mock( Google_Service_Gmail::class );
		$service->users_messages = $users_messages;

		$settings = Mockery::mock( Email_Account_Settings_Interface::class );

		$sut = Mockery::mock( Gmail_Email_Connection::class, array( $settings, $this->logger ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$sut->allows( 'get_gmail_service' )->andReturn( $service );

		return $sut;
	}

	/**
	 * Build a full Gmail message (format=raw) from a fixture, with the given labels.
	 *
	 * @param string   $fixture_filename The `.eml` fixture under tests/_data/wpunit/.
	 * @param string[] $label_ids        The Gmail label ids.
	 */
	private function make_full_message( string $fixture_filename, array $label_ids ): Gmail_Message {
		$rfc2822 = (string) file_get_contents( codecept_root_dir( 'tests/_data/wpunit/' . $fixture_filename ) );

		$message = new Gmail_Message();

		/**
		 * Gmail returns the raw message base64url-encoded.
		 *
		 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		 */
		$message->setRaw( strtr( base64_encode( $rfc2822 ), '+/', '-_' ) );
		$message->setLabelIds( $label_ids );

		return $message;
	}

	/**
	 * Each listed Gmail message is fetched, parsed, and mapped to a Fetched_Email whose coordinates
	 * carry the Gmail id as the remote uid (folder/UIDVALIDITY are IMAP-only and stay null).
	 *
	 * @covers ::retrieve_emails
	 */
	public function test_retrieve_emails_maps_gmail_messages_to_fetched_emails(): void {

		$sut = $this->make_sut_listing(
			array( 'gmail-id-1' => $this->make_full_message( 'test_save_new.eml', array( 'INBOX', 'UNREAD' ) ) )
		);

		$result  = $sut->retrieve_emails( ( new DateTime() )->modify( '-7 days' ) );
		$fetched = $result->values()->all();

		$this->assertCount( 1, $fetched );
		$first = $fetched[0];
		$this->assertInstanceOf( Fetched_Email::class, $first );
		$this->assertSame( '[Wordfence Alert] Problems found on bhwp.ie', $first->message->getSubject() );
		$this->assertSame( 'gmail-id-1', $first->coordinates->remote_uid );
		$this->assertNull( $first->coordinates->folder );
		$this->assertNull( $first->coordinates->uid_validity );
		$this->assertSame( $first->message->getMessageId(), $first->coordinates->message_id );
		// UNREAD label present → not read.
		$this->assertFalse( $first->is_remote_read );
	}

	/**
	 * Read state is derived from the `UNREAD` label: present → unread, absent → read.
	 *
	 * @covers ::retrieve_emails
	 * @covers ::is_read_from_labels
	 */
	public function test_retrieve_emails_reads_label_state(): void {

		$sut = $this->make_sut_listing(
			array(
				'unread-id' => $this->make_full_message( 'test_save_new.eml', array( 'INBOX', 'UNREAD' ) ),
				'read-id'   => $this->make_full_message( 'html-no-plain-text.eml', array( 'INBOX' ) ),
			)
		);

		$by_uid = array();
		foreach ( $sut->retrieve_emails( ( new DateTime() )->modify( '-7 days' ) ) as $fetched_email ) {
			$by_uid[ $fetched_email->coordinates->remote_uid ] = $fetched_email->is_remote_read;
		}

		$this->assertFalse( $by_uid['unread-id'] );
		$this->assertTrue( $by_uid['read-id'] );
	}

	/**
	 * Build the fetcher with `get_gmail_service()` overridden to return a service whose `users`
	 * resource is the given mock.
	 *
	 * @param Users $users The mocked users resource.
	 */
	private function make_sut_with_users( Users $users ): Gmail_Email_Connection {
		$service        = Mockery::mock( Google_Service_Gmail::class );
		$service->users = $users;

		$sut = Mockery::mock( Gmail_Email_Connection::class, array( Mockery::mock( Email_Account_Settings_Interface::class ), $this->logger ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$sut->allows( 'get_gmail_service' )->andReturn( $service );

		return $sut;
	}

	/**
	 * Making the getProfile call returns true on success.
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_returns_true_on_success(): void {
		$users = Mockery::mock( Users::class );
		$users->expects( 'getProfile' )->with( 'me' )->once()->andReturn( new Profile() );

		$this->assertTrue( $this->make_sut_with_users( $users )->test_connection() );
	}

	/**
	 * An authorization failure propagates from test_connection().
	 *
	 * @covers ::test_connection
	 */
	public function test_test_connection_rethrows_on_failure(): void {
		$users = Mockery::mock( Users::class );
		$users->allows( 'getProfile' )->andThrow( new \RuntimeException( 'invalid_grant' ) );

		$this->expectException( \RuntimeException::class );
		$this->make_sut_with_users( $users )->test_connection();
	}
}

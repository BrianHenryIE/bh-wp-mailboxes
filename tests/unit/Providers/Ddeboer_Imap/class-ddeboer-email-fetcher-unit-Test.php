<?php
/**
 *
 * @package brianhenryie/bh-wp-mailboxes
 * @author  BrianHenryIE <BrianHenryIE@gmail.com>
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Codeception\Stub;
use ImapEngine\Imap\ConnectionInterface;
use ImapEngine\Imap\MailboxInterface;
use ImapEngine\Imap\Message\EmailAddress;
use ImapEngine\Imap\MessageInterface;
use ImapEngine\Imap\MessageIteratorInterface;
use ImapEngine\Imap\ServerInterface;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher
 */
class ImapEngine_Imap_Email_Fetcher_Unit_Test extends Unit_Testcase {

	protected function setup(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * @covers \BrianHenryIE\WP_Emails\API\ImapEngine_Imap\Email_Fetcher::retrieve_emails
	 */
	public function test_happy_path() {

		// @see https://stackoverflow.com/questions/15907249/how-can-i-mock-a-class-that-implements-the-iterator-interface-using-phpunit
		$this->markTestIncomplete( 'Needs container to stop making actual IMAP calls' );

		$mailbox_settings = Stub::makeEmpty(
			Mailbox_Settings_Interface::class,
			array(
				'get_email_imap_server'        => 'server',
				'get_email_account_username'   => 'username',
				'get_email_account_password'   => 'password',
				'after_reconcile_email_action' => 'read',
				'get_from_email_regex'         => null,
				'get_identifier_regex'         => null,
				'get_credentials'              => $this->makeEmpty(
					IMAP_Credentials_Interface::class,
					array(
						'get_email_imap_server' => 'mail.example.com',
					)
				),
			)
		);

		$logger = new ColorLogger();

		$new_server = new class() implements Server_Container_Interface {
			public function get_server( $url_or_ip ): ServerInterface {

				$from = new EmailAddress( 'test', 'example.com' );

				$messages = array(
					Stub::makeEmpty(
						MessageInterface::class,
						array(
							'getDate',
							'getTimestamp',
							'getFrom' => $from,
						)
					),
				);

				// @see https://stackoverflow.com/questions/15907249/how-can-i-mock-a-class-that-implements-the-iterator-interface-using-phpunit
				$messages_iterator = Stub::makeEmpty(
					MessageIteratorInterface::class,
					array(
						'rewind'  => '',
						'key'     => 1,
						'valid'   => Stub::consecutive( true, true, false ),
						'current' => Stub::consecutive( $messages[0], $messages[0] ),
						'next'    => Stub::consecutive( $messages[0], $messages[0] ),
						'yield'   => Stub::consecutive( $messages[0], $messages[0] ),
					)
				);

				$mailbox = Stub::makeEmpty(
					MailboxInterface::class,
					array(
						'getMessages' => $messages_iterator,

					)
				);

				$connection = Stub::makeEmpty(
					ConnectionInterface::class,
					array(
						'getMailbox' => $mailbox,
					)
				);

				$mock_server = Stub::makeEmpty(
					ServerInterface::class,
					array(
						'authenticate' => $connection,
					)
				);

				return $mock_server;
			}
		};

		\WP_Mock::userFunction(
			'sanitize_title',
			array(
				'return_arg' => 0,
			)
		);

		\WP_Mock::userFunction(
			'get_option',
			array(
				'return' => false,
			)
		);

		$cpt = 'test-post-type-name';

		$sut = new ImapEngine_Imap_Email_Fetcher( $cpt, $mailbox_settings, $logger );

		$b = \DateTime::createFromFormat( 'U', 0 );

		$since_datetime = new \DateTime();
		$emails         = $sut->retrieve_emails( $since_datetime );

		$this->assertCount( 1, $emails );
	}

	/**
	 * @covers ::test_credentials
	 */
	public function test_test_credentials(): void {

		$this->markTestIncomplete( 'Really need to use a container' );

		$mailbox_settings = Stub::makeEmpty(
			Mailbox_Settings_Interface::class,
			array(
				'get_email_imap_server'        => 'server',
				'get_email_account_username'   => 'username',
				'get_email_account_password'   => 'password',
				'after_reconcile_email_action' => 'read',
				'get_from_email_regex'         => null,
				'get_identifier_regex'         => null,
				'get_credentials'              => $this->makeEmpty(
					IMAP_Credentials_Interface::class,
					array(
						'get_email_imap_server' => 'mail.example.com',
					)
				),
			)
		);

		$logger = new ColorLogger();

		$new_server = new class() implements Server_Container_Interface {
			public function get_server( $url_or_ip ): ServerInterface {

				$from = new EmailAddress( 'test', 'example.com' );

				$messages = array(
					Stub::makeEmpty(
						MessageInterface::class,
						array(
							'getDate',
							'getTimestamp',
							'getFrom' => $from,
						)
					),
				);

				// @see https://stackoverflow.com/questions/15907249/how-can-i-mock-a-class-that-implements-the-iterator-interface-using-phpunit
				$messages_iterator = Stub::makeEmpty(
					MessageIteratorInterface::class,
					array(
						'rewind'  => '',
						'key'     => 1,
						'valid'   => Stub::consecutive( true, true, false ),
						'current' => Stub::consecutive( $messages[0], $messages[0] ),
						'next'    => Stub::consecutive( $messages[0], $messages[0] ),
						'yield'   => Stub::consecutive( $messages[0], $messages[0] ),
					)
				);

				$mailbox = Stub::makeEmpty(
					MailboxInterface::class,
					array(
						'getMessages' => $messages_iterator,

					)
				);

				$connection = Stub::makeEmpty(
					ConnectionInterface::class,
					array(
						'getMailbox' => $mailbox,
					)
				);

				$mock_server = Stub::makeEmpty(
					ServerInterface::class,
					array(
						'authenticate' => $connection,
					)
				);

				return $mock_server;
			}
		};

		\WP_Mock::userFunction(
			'sanitize_title',
			array(
				'return_arg' => 0,
			)
		);

		\WP_Mock::userFunction(
			'get_option',
			array(
				'return' => false,
			)
		);

		$cpt = 'test-post-type-name';

		$sut = new ImapEngine_Imap_Email_Fetcher( $cpt, $mailbox_settings, $logger );

		$result = $sut->test_credentials();

		$this->assertTrue( $result['succcess'] );
	}
}

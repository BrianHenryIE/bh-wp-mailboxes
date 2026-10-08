<?php
/**
 * Unit tests for the Email_Thread value object.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Model\Email_Thread
 */
class Email_Thread_Unit_Test extends Unit_Testcase {

	/**
	 * A minimal saved email.
	 *
	 * @param int $post_id The email's post id.
	 */
	private function make_email( int $post_id ): BH_Email {
		$message = ( new MailMimeParser() )->parse( "From: a@example.org\r\nSubject: Hi\r\nMessage-ID: <{$post_id}@example.org>\r\n\r\nBody", true );

		return new BH_Email(
			post_id: $post_id,
			post_type: 'test_email',
			email_account_local_id: 1,
			imessage: $message,
			message_id: "{$post_id}@example.org",
			subject: 'Hi',
			from_email: 'a@example.org',
		);
	}

	/**
	 * A thread with no emails or one email has nothing related to show; two or more does.
	 *
	 * @covers ::__construct
	 * @covers ::count
	 * @covers ::has_related_emails
	 */
	public function test_count_and_has_related_emails(): void {
		$empty  = new Email_Thread( term_id: 0, slug: '' );
		$single = new Email_Thread( term_id: 5, slug: 'abc', emails: array( $this->make_email( 1 ) ) );
		$pair   = new Email_Thread( term_id: 5, slug: 'abc', emails: array( $this->make_email( 1 ), $this->make_email( 2 ) ) );

		$this->assertSame( 0, $empty->count() );
		$this->assertFalse( $empty->has_related_emails() );

		$this->assertSame( 1, $single->count() );
		$this->assertFalse( $single->has_related_emails() );

		$this->assertSame( 2, $pair->count() );
		$this->assertTrue( $pair->has_related_emails() );
	}

	/**
	 * Emails created before threading existed hydrate with no references and no thread.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Model\BH_Email::__construct
	 */
	public function test_bh_email_thread_properties_default_to_empty(): void {
		$email = $this->make_email( 1 );

		$this->assertSame( array(), $email->in_reply_to );
		$this->assertSame( array(), $email->references );
		$this->assertNull( $email->thread_term_id );
	}
}

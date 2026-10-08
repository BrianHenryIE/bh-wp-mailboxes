<?php
/**
 * Tests for Email_Thread_Linker's header parsing (the database-backed linking is covered in wpunit).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker
 */
class Email_Thread_Linker_Unit_Test extends Unit_Testcase {

	/**
	 * @covers ::normalize_message_id
	 */
	public function test_normalize_message_id_strips_brackets_and_whitespace(): void {
		$this->assertSame( 'abc@example.com', Email_Thread_Linker::normalize_message_id( '<abc@example.com>' ) );
		$this->assertSame( 'abc@example.com', Email_Thread_Linker::normalize_message_id( "  <abc@example.com>\r\n" ) );
		$this->assertSame( 'abc@example.com', Email_Thread_Linker::normalize_message_id( 'abc@example.com' ) );
		$this->assertSame( '', Email_Thread_Linker::normalize_message_id( '<>' ) );
	}

	/**
	 * @covers ::get_header_ids
	 */
	public function test_get_header_ids_returns_normalized_ids_in_order(): void {
		$raw     = "From: a@example.com\r\nSubject: Re: Hi\r\nMessage-ID: <c@example.com>\r\n"
			. "In-Reply-To: <b@example.com>\r\n"
			. "References: <a@example.com>\r\n <b@example.com>\r\n"
			. "Content-Type: text/plain\r\n\r\nBody";
		$message = ( new MailMimeParser() )->parse( $raw, true );

		$this->assertSame( array( 'b@example.com' ), Email_Thread_Linker::get_header_ids( $message, HeaderConsts::IN_REPLY_TO ) );
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), Email_Thread_Linker::get_header_ids( $message, HeaderConsts::REFERENCES ) );
	}

	/**
	 * @covers ::get_header_ids
	 */
	public function test_get_header_ids_is_empty_when_header_absent(): void {
		$raw     = "From: a@example.com\r\nSubject: Hi\r\nMessage-ID: <a@example.com>\r\nContent-Type: text/plain\r\n\r\nBody";
		$message = ( new MailMimeParser() )->parse( $raw, true );

		$this->assertSame( array(), Email_Thread_Linker::get_header_ids( $message, HeaderConsts::IN_REPLY_TO ) );
		$this->assertSame( array(), Email_Thread_Linker::get_header_ids( $message, HeaderConsts::REFERENCES ) );
	}

	/**
	 * Some clients repeat an id (e.g. References ending with the In-Reply-To id twice).
	 *
	 * @covers ::get_header_ids
	 */
	public function test_get_header_ids_deduplicates(): void {
		$raw     = "From: a@example.com\r\nSubject: Hi\r\nMessage-ID: <c@example.com>\r\n"
			. "References: <a@example.com> <b@example.com> <b@example.com>\r\n"
			. "Content-Type: text/plain\r\n\r\nBody";
		$message = ( new MailMimeParser() )->parse( $raw, true );

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), Email_Thread_Linker::get_header_ids( $message, HeaderConsts::REFERENCES ) );
	}
}

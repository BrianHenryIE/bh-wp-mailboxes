<?php
/**
 * Tests for Email_Thread_Linker's header parsing (the database-backed linking is covered in wpunit).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use Mockery;
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
	/**
	 * Up to the limit, References is matched in full; beyond it, the first id and the most recent ids are kept.
	 *
	 * @covers ::bound_references
	 */
	public function test_bound_references_keeps_the_first_and_most_recent_ids(): void {
		$short = array( 'a@example.org', 'b@example.org' );
		$this->assertSame( $short, Email_Thread_Linker::bound_references( $short ) );

		$at_limit = array_map( fn( int $number ): string => "ref-{$number}@example.org", range( 1, Email_Thread_Linker::MAX_MATCHED_REFERENCES ) );
		$this->assertSame( $at_limit, Email_Thread_Linker::bound_references( $at_limit ) );

		$long    = array_map( fn( int $number ): string => "ref-{$number}@example.org", range( 1, 60 ) );
		$bounded = Email_Thread_Linker::bound_references( $long );

		$this->assertCount( Email_Thread_Linker::MAX_MATCHED_REFERENCES, $bounded );
		$this->assertSame( 'ref-1@example.org', $bounded[0], 'The root is kept.' );
		$this->assertSame( 'ref-42@example.org', $bounded[1], 'Then the most recent ids.' );
		$this->assertSame( 'ref-60@example.org', $bounded[ Email_Thread_Linker::MAX_MATCHED_REFERENCES - 1 ] );
	}

	/**
	 * When an email cannot be moved while merging threads, it is logged and not reported as merged.
	 *
	 * @covers ::move_thread
	 * @covers ::set_thread
	 */
	public function test_move_thread_reports_only_the_emails_moved(): void {
		$taxonomy = Mockery::mock( BH_Email_Thread_Taxonomy::class );

		$sut = new class( 'test_email', $taxonomy, null, $this->logger ) extends Email_Thread_Linker {
			/**
			 * Expose the protected merge step.
			 *
			 * @param int    $from_term_id The thread being merged away.
			 * @param int    $into_term_id The thread being kept.
			 * @param string $taxonomy     The thread taxonomy.
			 *
			 * @return int[]
			 */
			public function move( int $from_term_id, int $into_term_id, string $taxonomy ): array {
				return $this->move_thread( $from_term_id, $into_term_id, $taxonomy );
			}
		};

		$failure = Mockery::mock( \WP_Error::class );
		$failure->allows( 'get_error_message' )->andReturn( 'Could not write the term relationship.' );

		\WP_Mock::userFunction( 'get_objects_in_term' )->with( 8, 'test_email_thread' )->andReturn( array( '11', '12' ) );
		\WP_Mock::userFunction( 'wp_set_object_terms' )->andReturnUsing(
			fn( int $post_id ) => 12 === $post_id ? $failure : array( 7 )
		);

		$moved = $sut->move( 8, 7, 'test_email_thread' );

		$this->assertSame( array( 11 ), $moved );
		$this->assertTrue( $this->logger->hasWarningThatContains( 'Failed to assign email {post_id} to thread {term_id}' ) );
		$this->assertTrue( $this->logger->hasInfoThatContains( '({count} of {total} emails)' ) );
	}
}

<?php
/**
 * Unit tests for the Demo mailbox's fetching connection.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTimeImmutable;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Demo_Fetching_Connection
 */
class Demo_Fetching_Connection_Unit_Test extends Unit_Testcase {

	/**
	 * Build the connection; its constructor registers hooks, so add_filter / add_action are stubbed.
	 */
	private function make_sut(): Demo_Fetching_Connection {

		\WP_Mock::userFunction( 'add_filter' );
		\WP_Mock::userFunction( 'add_action' );
		// No simulated connection failure is configured.
		\WP_Mock::userFunction( 'get_option' )->andReturn( false );

		return new Demo_Fetching_Connection(
			Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class ),
			Mockery::mock( Email_Account_Settings_Interface::class ),
			Mockery::mock( Email_WP_Post_Repository::class ),
		);
	}

	/**
	 * It has its own name.
	 *
	 * @covers ::get_friendly_name
	 */
	public function test_get_friendly_name(): void {
		$this->assertSame( 'Demo (fetches example emails)', $this->make_sut()->get_friendly_name() );
	}

	/**
	 * It fetches the demo's own emails, not the Fixtures mailbox's: the plain-text, HTML, attachment and
	 * two thread emails.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_Fixtures_Connection::retrieve_emails
	 */
	public function test_retrieve_emails_returns_the_demo_emails(): void {

		$emails = $this->make_sut()->retrieve_emails( new DateTimeImmutable( '@0' ) );

		$subjects = array_map( fn( Fetched_Email $fetched ): string => (string) $fetched->message->getSubject(), $emails->all() );
		sort( $subjects );

		$this->assertSame(
			array(
				'Do you deliver to Galway?',
				'Order #1042 arrived damaged',
				'Packing list for shipment 7781',
				'Re: Order #1042 arrived damaged',
				'Your invoice INV-2026-0412 from Acme Supplies',
			),
			$subjects
		);

		foreach ( $emails as $fetched ) {
			$this->assertNotSame( '', $fetched->coordinates->message_id, 'Each demo email maps its Message-ID.' );
		}
	}

	/**
	 * The examples cover what the Demo mailbox promises: a plain-text-only email, an HTML and plain-text email,
	 * and an email with an attachment.
	 *
	 * @covers \BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Mock_Mailbox_Fixtures_Connection::retrieve_emails
	 */
	public function test_demo_emails_cover_each_kind_of_email(): void {

		$messages = array();
		foreach ( $this->make_sut()->retrieve_emails( new DateTimeImmutable( '@0' ) ) as $fetched ) {
			$messages[ (string) $fetched->message->getSubject() ] = $fetched->message;
		}

		$plain_text = $messages['Do you deliver to Galway?'];
		$this->assertNotNull( $plain_text->getTextContent() );
		$this->assertNull( $plain_text->getHtmlContent() );

		$html_and_plain = $messages['Your invoice INV-2026-0412 from Acme Supplies'];
		$this->assertNotNull( $html_and_plain->getTextContent() );
		$this->assertNotNull( $html_and_plain->getHtmlContent() );

		$with_attachment = $messages['Packing list for shipment 7781'];
		$this->assertCount( 1, $with_attachment->getAllAttachmentParts() );
		$this->assertSame( 'packing-list-7781.csv', $with_attachment->getAllAttachmentParts()[0]->getFilename() );
	}
}

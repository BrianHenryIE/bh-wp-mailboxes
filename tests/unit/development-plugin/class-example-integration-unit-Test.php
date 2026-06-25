<?php
/**
 * Unit tests for the example parent-plugin integration.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;
use ZBateson\MailMimeParser\IMessage;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Example_Integration
 */
class Example_Integration_Unit_Test extends Unit_Testcase {

	/**
	 * The new-email handler logs the subject and records a note on the email's own log.
	 *
	 * @covers ::log_new_email
	 */
	public function test_log_new_email_logs_subject_and_adds_note(): void {

		// BH_Email is a readonly value object (not mockable); build a real one with a mocked IMessage.
		$bh_email = new BH_Email(
			post_id: 42,
			post_type: 'bh_email',
			email_account_local_id: 1,
			imessage: Mockery::mock( IMessage::class ),
			message_id: '<id@example.com>',
			subject: 'Wordfence Alert',
			from_email: 'sender@example.com',
		);

		$new_email = Mockery::mock( New_Email_Interface::class );
		$new_email->allows( 'get_email' )->andReturn( $bh_email );
		$new_email->expects( 'add_local_note' )->with( Mockery::type( 'string' ), 'info' )->once();

		new Example_Integration( $this->logger )->log_new_email(
			'test-plugin',
			BH_Email_Account_Fixture::make(),
			$new_email
		);

		$this->assertTrue( $this->logger->hasInfoThatContains( 'Wordfence Alert' ) );
	}
}

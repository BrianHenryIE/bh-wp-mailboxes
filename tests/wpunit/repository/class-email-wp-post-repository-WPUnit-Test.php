<?php
/**
 * TODO: move to integration test?
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use Mockery;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository
 */
class Email_WP_Post_Repository_WPUnit_Test extends \BrianHenryIE\WP_Mailboxes\WPUnit_Testcase {

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Mockery::mock( \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface::class );
		$this->settings->expects( 'get_emails_cpt_underscored_20' )->andReturn( 'test_post_type' );
		$this->settings->expects( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Post Type' );

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );
		$cpt->register_cpt();

		$cpt->register_post_statuses();
	}

	/**
	 * @covers ::save_new
	 */
	public function test_save_new(): void {

		$post_type        = 'test_post_type';
		$bh_email_factory = Mockery::mock( BH_Email_Factory::class );
		$bh_email_factory = new BH_Email_Factory( $this->logger );

		$sut = new Email_WP_Post_Repository( $post_type, $bh_email_factory );

		$email_filepath = codecept_root_dir( 'tests/_data/wpunit/test_save_new.eml' );
		$email_contents = file_get_contents( $email_filepath );
		$parser         = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( $email_contents, true );

		$email_account = Mockery::mock( Email_Account_Settings_Interface::class );
		$email_account->expects( 'get_account_email_address' )->andReturn( 'test@example.com' );

		$result = $sut->save_new(
			$email,
			$this->settings,
			$email_account
		);

		$this->assertEquals( '[Wordfence Alert] Problems found on bhwp.ie', $result->get_subject() );

		// "Date: Wed, 30 Jul 2025 03:38:07 +0000".
		$this->assertEquals( '2025-07-30 03:38:07', $result->get_sent_at()->format( 'Y-m-d H:i:s' ) );
	}
}

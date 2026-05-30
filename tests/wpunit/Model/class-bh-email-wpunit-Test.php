<?php

namespace BrianHenryIE\WP_Mailboxes\Model;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Codeception\Stub\Expected;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Model\BH_Email
 */
class BH_Email_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * @covers ::save
	 */
	public function test_save_email(): void {

		$this->markTestSkipped();

		$logger   = new ColorLogger();
		$mailbox  = $this->makeEmpty(
			Mailbox_Settings_Interface::class,
			array(
				'get_account_unique_friendly_name' => Expected::atLeastOnce(
					function () {
						return 'brianhenryie@gmail.com';
					}
				),
			)
		);
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_configured_mailbox_settings' => Expected::once(
					function () use ( $mailbox ) {
						return array( $mailbox );
					}
				),
			)
		);

		$cpt = new BH_Email_CPT( $settings, $logger );
		$cpt->register_cpt();
		$cpt->register_mailboxes_taxonomy();
		$cpt->register_mailbox();

		$account_category_slug = sanitize_title( 'brianhenryie@gmail.com' );
		$mailbox_category      = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		$new_email                        = array();
		$new_email['cpt']                 = 'bh_wp_email';
		$new_email['account_category_id'] = $mailbox_category->term_id;
		$new_email['email_id']            = '<email_id@sending.server.com>';
		$new_email['headers']             = array();
		$new_email['subject']             = 'Test subject';
		$new_email['from_email']          = 'brianhenryie@gmail.com';
		$new_email['from_name']           = 'Brian Henry';
		$new_email['body_text']           = 'plain text body';
		$new_email['body_html']           = '<p>html body</p>';
		$new_email['meta_data']           = array();
		$new_email['meta_data']['udate']  = time();

		$email = BH_Email::create_from_array( $new_email );

		$id = $email->save();

		$post = get_post( $id );

		$this->assertEquals( 'Test subject', $post->post_title );
		$this->assertEquals( 'bh_wp_email', $post->post_type );
	}
}

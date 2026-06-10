<?php

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Codeception\Stub\Expected;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT
 */
class BH_Email_CPT_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Check the post type is registered.
	 *
	 * @covers ::register_cpt
	 */
	public function test_creating_the_cpt(): void {

		$logger = new ColorLogger();

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_friendly_name'  => Expected::atLeastOnce(
					fn() => 'my-plugin-emails'
				),
				'get_cpt_underscored_20' => Expected::once(
					fn() => 'my_plugin_emails'
				),
			)
		);

		$sut = new BH_Email_CPT( $settings, $logger );

		$before_registered_post_types = get_post_types();

		assert( ! in_array( 'my_plugin_emails', $before_registered_post_types, true ) );

		$sut->register_cpt();

		$after_registered_post_types = get_post_types();

		$this->assertContains( 'my_plugin_emails', $after_registered_post_types );
	}

	/**
	 * @covers ::register_mailbox
	 */
	public function test_registering_the_account(): void {

		$logger = new ColorLogger();

		$mailbox = $this->makeEmpty(
			Email_Account_Settings_Interface::class,
			array(
				'get_account_unique_friendly_name' => Expected::atLeastOnce(
					fn() => 'brianhenryie@gmail.com'
				),
			)
		);

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_friendly_name'           => Expected::atLeastOnce(
					fn() => 'Test Plugin Email'
				),
				'get_cpt_underscored'             => Expected::once(
					fn() => 'test_plugin_email'
				),
				'get_configured_mailbox_settings' => Expected::once(
					fn() => array( $mailbox )
				),
			)
		);

		$sut = new BH_Email_CPT( $settings, $logger );

		$account_category_slug = sanitize_title( 'brianhenryie@gmail.com' );

		// false when it does not exist.
		$mailbox_category = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		assert( false === $mailbox_category );

		$sut->register_mailboxes_taxonomy();
		$sut->register_mailbox();

		$mailbox_category = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		$this->assertNotFalse( $mailbox_category );
	}
}

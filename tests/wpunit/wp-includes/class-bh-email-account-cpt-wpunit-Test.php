<?php
/**
 * WPUnit tests for BH_Email_Account_CPT: post type + status registration.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT
 */
class BH_Email_Account_CPT_WPUnit_Test extends WPUnit_Testcase {

	/** @var string Account CPT slug used in this suite. */
	private string $post_type = 'test_acct_cpt';

	/**
	 * Build the SUT with settings returning the test post type + friendly name.
	 */
	private function make_sut(): BH_Email_Account_CPT {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $this->post_type );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Accounts' );

		return new BH_Email_Account_CPT( $settings, $this->logger );
	}

	/**
	 * The custom post type is registered, private (not public, excluded from search), and supports
	 * title + comments (comments are used to log changes).
	 *
	 * @covers ::__construct
	 * @covers ::register_cpt
	 */
	public function test_register_cpt(): void {

		$this->assertNotContains( $this->post_type, get_post_types() );

		$this->make_sut()->register_cpt();

		$this->assertContains( $this->post_type, get_post_types() );

		$post_type_object = get_post_type_object( $this->post_type );
		$this->assertNotNull( $post_type_object );
		$this->assertFalse( $post_type_object->public, 'Account CPT must not be public.' );
		$this->assertTrue( $post_type_object->exclude_from_search );
		$this->assertTrue( post_type_supports( $this->post_type, 'title' ) );
		$this->assertTrue( post_type_supports( $this->post_type, 'comments' ) );
	}

	/**
	 * The active and inactive account post statuses are registered.
	 *
	 * @covers ::register_post_statuses
	 */
	public function test_register_post_statuses(): void {

		$this->make_sut()->register_post_statuses();

		$this->assertNotNull( get_post_status_object( 'bh_email_ac_active' ) );
		$this->assertNotNull( get_post_status_object( 'bh_email_ac_inactive' ) );
	}
}

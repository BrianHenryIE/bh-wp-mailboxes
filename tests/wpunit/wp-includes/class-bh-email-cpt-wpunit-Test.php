<?php

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Codeception\Stub\Expected;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT
 */
class BH_Email_CPT_WPUnit_Test extends WPUnit_Testcase {

	protected function make_settings(
		string $plugin_slug = 'my-test-plugin',
		string $emails_cpt_friendly_name = 'My Emails',
		?string $private_uploads_directory_name = null,
		string $emails_cpt_underscored_20 = 'my_emails_cpt',
		?string $email_accounts_cpt_underscored_20 = null,
		string $emails_cpt_dashed = 'my-emails-cpt',
		?string $email_accounts_cpt_dashed = null,
		?array $cron_schedules = null,
	): BH_WP_Mailboxes_Settings_Interface {
		$mock = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$mock->allows( 'get_plugin_slug' )->andReturn( $plugin_slug );
		$mock->allows( 'get_emails_cpt_friendly_name' )->andReturn( $emails_cpt_friendly_name );
		$mock->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt_underscored_20 );

		$private_uploads_directory_name && $mock->allows( 'get_private_uploads_directory_name' )->andReturn( $private_uploads_directory_name );
		$email_accounts_cpt_underscored_20 && $mock->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $email_accounts_cpt_underscored_20 );
		$email_accounts_cpt_dashed && $mock->allows( 'get_email_accounts_cpt_dashed' )->andReturn( $email_accounts_cpt_dashed );
		$cron_schedules && $mock->allows( 'get_cron_schedules' )->andReturn( $cron_schedules );

		return $mock;
	}

	/**
	 * Check the post type is registered.
	 *
	 * @covers ::__construct
	 * @covers ::register_cpt
	 */
	public function test_creating_the_cpt(): void {

		$logger = new ColorLogger();

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_emails_cpt_friendly_name'  => Expected::atLeastOnce(
					fn() => 'my-plugin-emails'
				),
				'get_emails_cpt_underscored_20' => Expected::once(
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
	 * Prevent_content_edits ignores posts of a different post type.
	 *
	 * @covers ::prevent_content_edits
	 */
	public function test_prevent_content_edits_ignores_other_post_types(): void {

		$sut = new BH_Email_CPT( $this->make_settings(), $this->logger );

		$incoming_data = array(
			'post_type'  => 'post',
			'post_title' => 'A regular post',
		);
		$postarr       = array( 'ID' => 0 );

		$result = $sut->prevent_content_edits( $incoming_data, $postarr );

		$this->assertSame( 'A regular post', $result['post_title'] );
	}

	/**
	 * The autosave script should be dequeued on the email edit screen.
	 *
	 * @covers ::disable_autosave
	 */
	public function test_disable_autosave_on_email_screen(): void {

		$sut = new BH_Email_CPT( $this->make_settings(), $this->logger );

		if ( ! wp_script_is( 'autosave', 'registered' ) ) {
			wp_register_script( 'autosave', false, array(), '1.0.0', true );
		}
		wp_enqueue_script( 'autosave' );
		$this->assertTrue( wp_script_is( 'autosave', 'enqueued' ) );

		set_current_screen( 'my_emails_cpt' );
		get_current_screen()->post_type = 'my_emails_cpt';

		$sut->disable_autosave();

		$this->assertFalse( wp_script_is( 'autosave', 'enqueued' ) );
	}

	/**
	 * The autosave script should be left alone on other post types' screens.
	 *
	 * @covers ::disable_autosave
	 */
	public function test_disable_autosave_ignores_other_screens(): void {

		$sut = new BH_Email_CPT( $this->make_settings(), $this->logger );

		if ( ! wp_script_is( 'autosave', 'registered' ) ) {
			wp_register_script( 'autosave', false, array(), '1.0.0', true );
		}
		wp_enqueue_script( 'autosave' );

		set_current_screen( 'post' );
		get_current_screen()->post_type = 'post';

		$sut->disable_autosave();

		$this->assertTrue( wp_script_is( 'autosave', 'enqueued' ) );
	}
}

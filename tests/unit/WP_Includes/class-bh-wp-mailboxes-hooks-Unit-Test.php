<?php
/**
 * Tests for BH_WP_Mailboxes_Hooks.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_WP_Mailboxes_Hooks
 */
class BH_WP_Mailboxes_Hooks_Unit_Test extends Unit_Testcase {

	/**
	 * Build and return a hooks instance with all WordPress function calls stubbed out.
	 */
	protected function make_sut(): BH_WP_Mailboxes_Hooks {

		$api      = $this->makeEmpty( API_Interface::class );
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_emails_cpt_underscored_20'         => 'test_email',
				'get_email_accounts_cpt_underscored_20' => 'test_email_account',
				'get_emails_cpt_friendly_name'          => 'Test Email',
				'get_email_accounts_cpt_friendly_name'  => 'Test Email Account',
				'get_plugin_slug'                       => 'test-plugin',
			)
		);

		\WP_Mock::userFunction( 'add_action' );
		\WP_Mock::userFunction( 'add_filter' );

		return new BH_WP_Mailboxes_Hooks( $api, $settings, $this->logger );
	}

	/**
	 * Constructor must complete without error, wiring up all hook groups.
	 *
	 * @covers ::__construct
	 * @covers ::define_cpt_hooks
	 * @covers ::define_cron_hooks
	 * @covers ::define_admin_ui_hooks
	 * @covers ::define_single_email_view_hooks
	 * @covers ::define_ajax_hooks
	 */
	public function test_constructor_registers_all_hooks_without_error(): void {

		$sut = $this->make_sut();

		$this->assertInstanceOf( BH_WP_Mailboxes_Hooks::class, $sut );
	}
}

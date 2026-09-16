<?php
/**
 * WPUnit tests for BH_WP_Mailboxes_Hooks CPT registration.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_WP_Mailboxes_Hooks
 */
class BH_WP_Mailboxes_Hooks_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Both the emails CPT and the accounts CPT must be registered on init.
	 *
	 * Regression: define_cpt_hooks() created two BH_Email_CPT instances, so the accounts CPT was never
	 * registered. Its posts still existed, so capability checks against them (e.g. the dashboard Activity
	 * widget's recent comments) emitted "post type … is not registered" notices.
	 *
	 * @covers ::define_cpt_hooks
	 */
	public function test_emails_and_accounts_cpts_are_registered_on_init(): void {

		$emails_cpt   = 'test_hooks_email';
		$accounts_cpt = 'test_hooks_account';

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Hooks Emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $accounts_cpt );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Hooks Accounts' );

		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();

		remove_all_actions( 'init' );

		// Constructing the hooks registers the CPT registration callbacks on `init`.
		new BH_WP_Mailboxes_Hooks( $api, $settings, $this->logger );

		do_action( 'init' );

		$this->assertTrue( post_type_exists( $emails_cpt ), 'The emails CPT should be registered.' );
		$this->assertTrue( post_type_exists( $accounts_cpt ), 'The accounts CPT should be registered.' );
	}

	/**
	 * The emails and accounts REST routes are registered under the mailbox's namespace on `rest_api_init`,
	 * whether or not a REST namespace is configured (they need a logged-in user, not the ingress setting).
	 *
	 * @covers ::define_rest_hooks
	 * @covers ::register_rest_controllers
	 */
	public function test_rest_controllers_are_registered_on_rest_api_init(): void {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class )->shouldIgnoreMissing();
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-hooks' );
		$settings->allows( 'get_rest_namespace' )->andReturn( null );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'test_hooks_email' );
		$settings->allows( 'get_emails_cpt_dashed' )->andReturn( 'test-hooks-email' );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( 'Test Hooks Emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'test_hooks_account' );
		$settings->allows( 'get_email_accounts_cpt_dashed' )->andReturn( 'test-hooks-account' );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( 'Test Hooks Accounts' );

		$api = Mockery::mock( API_Interface::class )->shouldIgnoreMissing();

		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_actions( 'rest_api_init' );
		remove_all_filters( 'rest_index' );

		new BH_WP_Mailboxes_Hooks( $api, $settings, $this->logger );

		try {
			$routes = rest_get_server()->get_routes();
		} finally {
			$wp_rest_server = null;
			remove_all_actions( 'rest_api_init' );
			remove_all_filters( 'rest_index' );
		}

		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-email', $routes );
		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-email/check', $routes );
		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-account', $routes );
		$this->assertArrayHasKey( '/test-hooks/v2/test-hooks-account/test-connection', $routes );
	}
}

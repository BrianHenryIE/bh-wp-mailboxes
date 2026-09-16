<?php
/**
 * WPUnit tests for the account modal's assets: the script is enqueued once with the REST settings the
 * admin scripts call the routes with.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Modal
 */
class Email_Account_Modal_WPUnit_Test extends WPUnit_Testcase {

	public function tearDown(): void {
		wp_dequeue_script( 'bh-wp-mailboxes-account-modal-my-plugin-accounts' );
		wp_deregister_script( 'bh-wp-mailboxes-account-modal-my-plugin-accounts' );
		wp_dequeue_style( 'bh-wp-mailboxes-account-modal-my-plugin-accounts' );
		wp_deregister_style( 'bh-wp-mailboxes-account-modal-my-plugin-accounts' );
		parent::tearDown();
	}

	protected function make_settings(): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'my-plugin' );
		$settings->allows( 'get_rest_namespace' )->andReturn( 'shop' );
		$settings->allows( 'get_emails_cpt_dashed' )->andReturn( 'my-plugin-emails' );
		$settings->allows( 'get_email_accounts_cpt_dashed' )->andReturn( 'my-plugin-accounts' );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'my_plugin_emails' );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( 'my_plugin_accounts' );
		return $settings;
	}

	/**
	 * The script is enqueued under an instance-scoped handle, localised with the mailbox's REST root,
	 * route bases and the cookie-auth nonce; the stylesheet comes with it.
	 *
	 * @covers ::enqueue_assets
	 * @covers ::get_script_handle
	 * @covers \BrianHenryIE\WP_Mailboxes\REST\REST_Namespace::url
	 */
	public function test_enqueue_assets_localises_the_rest_settings(): void {
		$settings = $this->make_settings();
		$modal    = new Email_Account_Modal( $settings, new Mailbox_Capabilities( $settings ) );
		$handle   = $modal->get_script_handle();

		$modal->enqueue_assets();

		$this->assertSame( 'bh-wp-mailboxes-account-modal-my-plugin-accounts', $handle );
		$this->assertTrue( wp_script_is( $handle, 'enqueued' ) );
		$this->assertTrue( wp_style_is( $handle, 'enqueued' ) );

		$data = wp_scripts()->get_data( $handle, 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'var bh_wp_mailboxes_ajax = ', $data );

		$decoded = json_decode( (string) substr( $data, (int) strpos( $data, '{' ), -1 ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( rest_url( 'shop/v2' ), $decoded['rest']['root'] );
		$this->assertSame( 'my-plugin-emails', $decoded['rest']['emails'] );
		$this->assertSame( 'my-plugin-accounts', $decoded['rest']['accounts'] );
		$this->assertSame( 1, wp_verify_nonce( $decoded['rest']['nonce'], 'wp_rest' ), 'A cookie-auth REST nonce.' );
	}

	/**
	 * Enqueueing is idempotent: the emails list page and a consumer's screen can both call it.
	 *
	 * @covers ::enqueue_assets
	 */
	public function test_enqueue_assets_is_idempotent(): void {
		$settings = $this->make_settings();
		$modal    = new Email_Account_Modal( $settings, new Mailbox_Capabilities( $settings ) );

		$modal->enqueue_assets();
		$data_after_first = wp_scripts()->get_data( $modal->get_script_handle(), 'data' );
		$modal->enqueue_assets();

		$this->assertSame( $data_after_first, wp_scripts()->get_data( $modal->get_script_handle(), 'data' ), 'Not localised twice.' );
	}
}

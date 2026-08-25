<?php
/**
 * Unit tests for the two settings-page-configured demo mailboxes registry.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;
use WP_Mock;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Dev_Mailboxes
 */
class Dev_Mailboxes_Unit_Test extends Unit_Testcase {

	/**
	 * The registry defines exactly the two demo mailboxes.
	 *
	 * @covers ::get_names
	 * @covers ::get_slugs
	 * @covers ::is_valid_slug
	 */
	public function test_registry_defines_mailbox_one_and_mailbox_two(): void {

		$this->assertSame( array( 'mailbox-one', 'mailbox-two' ), Dev_Mailboxes::get_slugs() );

		$this->assertTrue( Dev_Mailboxes::is_valid_slug( 'mailbox-one' ) );
		$this->assertTrue( Dev_Mailboxes::is_valid_slug( 'mailbox-two' ) );
		$this->assertFalse( Dev_Mailboxes::is_valid_slug( 'fixtures' ) );
		$this->assertFalse( Dev_Mailboxes::is_valid_slug( '' ) );

		$this->assertSame( 'Mailbox One Email', Dev_Mailboxes::get_names()['mailbox-one']['emails'] );
		$this->assertSame( 'Mailbox Two Accounts', Dev_Mailboxes::get_names()['mailbox-two']['accounts'] );
	}

	/**
	 * The REST-enabled flag is stored one wp_option per mailbox, slug underscored in the option name.
	 *
	 * @covers ::get_rest_enabled_option_name
	 * @covers ::is_rest_enabled
	 * @covers ::set_rest_enabled
	 */
	public function test_rest_enabled_option_round_trip(): void {

		$this->assertSame( 'bh_wp_mailboxes_dev_rest_enabled_mailbox_one', Dev_Mailboxes::get_rest_enabled_option_name( 'mailbox-one' ) );

		WP_Mock::userFunction( 'get_option' )
			->with( 'bh_wp_mailboxes_dev_rest_enabled_mailbox_two', false )
			->once()
			->andReturn( true );

		$this->assertTrue( Dev_Mailboxes::is_rest_enabled( 'mailbox-two' ) );

		WP_Mock::userFunction( 'update_option' )
			->with( 'bh_wp_mailboxes_dev_rest_enabled_mailbox_one', true )
			->once();

		Dev_Mailboxes::set_rest_enabled( 'mailbox-one', true );
	}

	/**
	 * The built settings derive the CPT keys from the friendly names, and use the mailbox slug as the
	 * REST namespace only when REST is enabled.
	 *
	 * @covers ::make_settings
	 */
	public function test_make_settings_uses_slug_as_rest_namespace_when_enabled(): void {

		WP_Mock::userFunction( 'get_option' )
			->with( 'bh_wp_mailboxes_dev_rest_enabled_mailbox_one', false )
			->andReturn( false, true );

		$settings = Dev_Mailboxes::make_settings( 'mailbox-one' );

		$this->assertSame( 'development-plugin', $settings->get_plugin_slug() );
		$this->assertSame( 'Mailbox One Email', $settings->get_emails_cpt_friendly_name() );
		$this->assertSame( 'Mailbox One Accounts', $settings->get_email_accounts_cpt_friendly_name() );
		$this->assertNull( $settings->get_rest_namespace() );

		$rest_enabled_settings = Dev_Mailboxes::make_settings( 'mailbox-one' );

		$this->assertSame( 'mailbox-one', $rest_enabled_settings->get_rest_namespace() );
	}

	/**
	 * The registered API instance is resolved by matching the mailbox's emails CPT key.
	 *
	 * @covers ::get_api
	 */
	public function test_get_api_matches_registered_mailbox_by_emails_cpt(): void {

		WP_Mock::userFunction( 'get_option' )->andReturn( false );

		$other_settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$other_settings->allows( 'get_emails_cpt_underscored_20' )->andReturns( 'fixtures_email' );
		$other_api = Mockery::mock( API::class );
		$other_api->allows( 'get_settings' )->andReturns( $other_settings );

		// sanitize_title is a passthru in the unit suite, so the derived CPT key equals the friendly name.
		$mailbox_two_settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$mailbox_two_settings->allows( 'get_emails_cpt_underscored_20' )->andReturns( 'Mailbox Two Email' );
		$mailbox_two_api = Mockery::mock( API::class );
		$mailbox_two_api->allows( 'get_settings' )->andReturns( $mailbox_two_settings );

		WP_Mock::onFilter( 'bh_wp_mailboxes_registered_mailboxes' )
			->with( array(), 'development-plugin' )
			->reply( array( $other_api, $mailbox_two_api ) );

		$this->assertSame( $mailbox_two_api, Dev_Mailboxes::get_api( 'mailbox-two' ) );
	}

	/**
	 * Null is returned when no registered mailbox matches (e.g. before `plugins_loaded`).
	 *
	 * @covers ::get_api
	 */
	public function test_get_api_returns_null_when_mailbox_not_registered(): void {

		WP_Mock::userFunction( 'get_option' )->andReturn( false );

		WP_Mock::onFilter( 'bh_wp_mailboxes_registered_mailboxes' )
			->with( array(), 'development-plugin' )
			->reply( array() );

		$this->assertNull( Dev_Mailboxes::get_api( 'mailbox-one' ) );
	}
}

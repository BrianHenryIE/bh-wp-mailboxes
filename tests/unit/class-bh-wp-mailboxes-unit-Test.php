<?php
/**
 * Unit tests for the Private_Uploads instance registry in the library entrypoint.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Private_Uploads\Private_Uploads;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use WP_Mock;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes
 */
class BH_WP_Mailboxes_Unit_Test extends Unit_Testcase {

	protected function setup(): void {
		parent::setup();

		// Reset the per-directory registry between tests.
		$property = new ReflectionClass( BH_WP_Mailboxes::class )->getProperty( 'private_uploads_instances' );
		$property->setValue( null, array() );

		// Reset the registered mailbox instances between tests.
		$mailboxes_property = new ReflectionClass( BH_WP_Mailboxes::class )->getProperty( 'mailboxes' );
		$mailboxes_property->setValue( null, array() );

		// The Private_Uploads hooks class registers its actions and filters in its constructor.
		WP_Mock::userFunction( 'add_action' );
		WP_Mock::userFunction( 'add_filter' );
		WP_Mock::userFunction( 'is_admin' )->andReturnFalse();
		WP_Mock::userFunction( 'wp_doing_ajax' )->andReturnFalse();
	}

	/**
	 * Invoke the protected static method under test.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The mailbox settings to build private uploads for.
	 */
	protected function invoke_make_private_uploads( BH_WP_Mailboxes_Settings_Interface $settings ): ?Private_Uploads {
		$method = new ReflectionMethod( BH_WP_Mailboxes::class, 'make_private_uploads' );
		$result = $method->invoke( null, $settings, $this->logger );
		return $result instanceof Private_Uploads ? $result : null;
	}

	/**
	 * Build mailbox settings whose private uploads directory name is as given.
	 *
	 * @param ?string $directory_name The private uploads directory name.
	 */
	protected function make_settings( ?string $directory_name ): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_private_uploads_directory_name' )->andReturns( $directory_name );
		$settings->allows( 'get_plugin_slug' )->andReturns( 'my-plugin' );
		return $settings;
	}

	/**
	 * Every mailbox of a plugin shares one attachments directory, so repeated calls (one per mailbox)
	 * must return one shared instance – previously each mailbox created its own, quintupling the post
	 * type registration, URL-check crons, and the publicly-accessible admin notice.
	 *
	 * @covers ::make_private_uploads
	 */
	public function test_same_directory_returns_same_private_uploads_instance(): void {

		$result_one = $this->invoke_make_private_uploads( $this->make_settings( 'my-plugin-email-attachments' ) );
		$result_two = $this->invoke_make_private_uploads( $this->make_settings( 'my-plugin-email-attachments' ) );

		$this->assertInstanceOf( Private_Uploads::class, $result_one );
		$this->assertSame( $result_one, $result_two );
	}

	/**
	 * @covers ::make_private_uploads
	 */
	public function test_different_directories_return_different_instances(): void {

		$result_one = $this->invoke_make_private_uploads( $this->make_settings( 'my-plugin-email-attachments' ) );
		$result_two = $this->invoke_make_private_uploads( $this->make_settings( 'my-plugin-other-directory' ) );

		$this->assertNotSame( $result_one, $result_two );
	}

	/**
	 * @covers ::make_private_uploads
	 */
	public function test_null_directory_returns_null(): void {

		$this->assertNull( $this->invoke_make_private_uploads( $this->make_settings( null ) ) );
	}

	/**
	 * The registry filter matches each instance by its own settings' plugin slug — not by a slug
	 * derived from the library file's path, which breaks when the library is nested in a plugin's
	 * vendor directory (the self-contained WordPress Playground build), where every registration
	 * was silently discarded and the dev settings page reported "The selected mailbox is not
	 * registered".
	 *
	 * @covers ::filter
	 */
	public function test_filter_matches_instances_by_their_own_plugin_slug(): void {

		$plugin_a_settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$plugin_a_settings->allows( 'get_plugin_slug' )->andReturns( 'plugin-a' );
		$plugin_a_api = Mockery::mock( \BrianHenryIE\WP_Mailboxes\API\API_Interface::class );
		$plugin_a_api->allows( 'get_settings' )->andReturns( $plugin_a_settings );

		$plugin_b_settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$plugin_b_settings->allows( 'get_plugin_slug' )->andReturns( 'plugin-b' );
		$plugin_b_api = Mockery::mock( \BrianHenryIE\WP_Mailboxes\API\API_Interface::class );
		$plugin_b_api->allows( 'get_settings' )->andReturns( $plugin_b_settings );

		$mailboxes_property = new ReflectionClass( BH_WP_Mailboxes::class )->getProperty( 'mailboxes' );
		$mailboxes_property->setValue( null, array( $plugin_a_api, $plugin_b_api ) );

		$this->assertSame( array( $plugin_a_api ), BH_WP_Mailboxes::filter( array(), 'plugin-a' ) );
		$this->assertSame( array( $plugin_b_api ), BH_WP_Mailboxes::filter( array(), 'plugin-b' ) );
		$this->assertSame( array(), BH_WP_Mailboxes::filter( array(), 'plugin-c' ) );
	}

	/**
	 * Earlier-registered mailboxes from other filter callbacks are preserved.
	 *
	 * @covers ::filter
	 */
	public function test_filter_appends_to_existing_mailboxes(): void {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturns( 'plugin-a' );
		$api = Mockery::mock( \BrianHenryIE\WP_Mailboxes\API\API_Interface::class );
		$api->allows( 'get_settings' )->andReturns( $settings );

		$mailboxes_property = new ReflectionClass( BH_WP_Mailboxes::class )->getProperty( 'mailboxes' );
		$mailboxes_property->setValue( null, array( $api ) );

		$existing = Mockery::mock( \BrianHenryIE\WP_Mailboxes\API\API_Interface::class );

		$this->assertSame( array( $existing, $api ), BH_WP_Mailboxes::filter( array( $existing ), 'plugin-a' ) );
	}

	/**
	 * The private-uploads trait default derives the post type by truncating the plugin slug to
	 * 12 characters + `_private`, which collides between plugins whose slugs share a prefix
	 * (e.g. `development-plugin` and `development-plugin-logger` both derive `development__private`).
	 *
	 * @covers ::make_private_uploads
	 */
	public function test_private_uploads_post_type_name_is_email_attachments_specific(): void {

		$private_uploads = $this->invoke_make_private_uploads( $this->make_settings( 'my-plugin-email-attachments' ) );

		$settings_property = new ReflectionClass( \BrianHenryIE\WP_Private_Uploads\API\API::class )->getProperty( 'settings' );

		/**
		 * The anonymous Private_Uploads_Settings_Interface implementation.
		 *
		 * @var \BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface $private_uploads_settings
		 */
		$private_uploads_settings = $settings_property->getValue( $private_uploads );

		$post_type_name = $private_uploads_settings->get_post_type_name();

		$this->assertSame( 'my_plug_email_attach', $post_type_name );
		$this->assertLessThanOrEqual( 20, strlen( $post_type_name ) );
	}
}

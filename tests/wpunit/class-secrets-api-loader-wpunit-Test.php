<?php
/**
 * WPUnit tests for loading the library's own copy of the Secrets API from Composer's vendor directory.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use Composer\InstalledVersions;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Secrets_API_Loader
 */
class Secrets_API_Loader_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * The API's `secrets.php` (constants and helpers) is included, the classes autoload, and the plugin
	 * bootstrap (`secrets-api.php`) is not included.
	 *
	 * @covers ::load
	 * @covers ::is_loaded
	 * @covers ::autoload
	 * @covers ::get_includes_dir
	 */
	public function test_load_makes_the_secrets_api_available(): void {
		$this->assertTrue( Secrets_API_Loader::load() );
		$this->assertTrue( Secrets_API_Loader::is_loaded() );

		$this->assertTrue( function_exists( 'wp_secrets_memzero' ) );
		$this->assertTrue( function_exists( 'wp_secrets_validate_name' ) );
		$this->assertTrue( defined( 'WP_SECRETS_ERROR_INVALID_NAME' ) );

		foreach ( array( 'WP_Secret', 'WP_Secret_Version', 'WP_Secrets_Cipher', 'WP_Secrets_Key_Manager', 'WP_Secrets_Config_Key_Provider', 'WP_Secrets_Option_Store', 'WP_Secrets_Libsodium_Provider' ) as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), $class_name );
		}
		foreach ( array( 'WP_Secrets_Provider', 'WP_Secrets_Keyring', 'WP_Secrets_Store' ) as $interface_name ) {
			$this->assertTrue( interface_exists( $interface_name ), $interface_name );
		}

		$this->assertFalse( function_exists( 'wp_secrets_api_bootstrap' ), 'The plugin bootstrap must not be included.' );
		$this->assertContains( array( Secrets_API_Loader::class, 'autoload' ), spl_autoload_functions() );
	}

	/**
	 * A second call is a no-op that still reports availability.
	 *
	 * @covers ::load
	 */
	public function test_load_is_idempotent(): void {
		$this->assertTrue( Secrets_API_Loader::load() );
		$this->assertTrue( Secrets_API_Loader::load() );
		$this->assertCount( 1, array_filter( spl_autoload_functions(), fn( $callback ) => array( Secrets_API_Loader::class, 'autoload' ) === $callback ), 'Registered once.' );
	}

	/**
	 * The class map is read from the files' declarations, so the `WP` capitalisation survives, and
	 * covers every class and interface file in the package's includes directory.
	 *
	 * @covers ::get_class_map
	 */
	public function test_class_map_enumerates_the_packages_classes(): void {
		$includes_dir = InstalledVersions::getInstallPath( 'wordpress/secrets-api' ) . '/src/wp-includes';
		$map          = Secrets_API_Loader::get_class_map();

		$this->assertSame( realpath( $includes_dir . '/class-wp-secrets-libsodium-provider.php' ), realpath( $map['WP_Secrets_Libsodium_Provider'] ) );
		$this->assertSame( realpath( $includes_dir . '/interface-wp-secrets-provider.php' ), realpath( $map['WP_Secrets_Provider'] ) );
		$this->assertArrayNotHasKey( 'Wp_Secret', $map );

		$files = array_merge( (array) glob( $includes_dir . '/class-*.php' ), (array) glob( $includes_dir . '/interface-*.php' ) );
		$this->assertCount( count( $files ), $map, 'One entry per class/interface file.' );
		foreach ( array_keys( $map ) as $class_name ) {
			$this->assertMatchesRegularExpression( '/^WP_Secret/', $class_name );
		}
	}

	/**
	 * Unknown classes are left to other autoloaders.
	 *
	 * @covers ::autoload
	 */
	public function test_autoload_ignores_other_classes(): void {
		Secrets_API_Loader::load();

		Secrets_API_Loader::autoload( 'Some\\Other\\Class_Name' );

		$this->assertFalse( class_exists( 'Some\\Other\\Class_Name', false ) );
	}
}

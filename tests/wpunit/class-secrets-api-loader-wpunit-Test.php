<?php
/**
 * WPUnit tests for loading the Secrets API's classes from Composer's vendor directory.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests build throwaway directory trees under the system temp dir.

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Secrets_API_Loader
 */
class Secrets_API_Loader_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Temporary directory trees created by a test, removed in tearDown.
	 *
	 * @var string[]
	 */
	protected array $temp_dirs = array();

	public function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->temp_dirs = array();
		parent::tearDown();
	}

	/**
	 * Only the API's classes (and the compat globals they need) are loaded, never `secrets.php`'s
	 * `wp_get_secret()` family: this library must not define those globals.
	 *
	 * @covers ::load
	 * @covers ::load_classes
	 * @covers ::is_loaded
	 * @covers ::find_includes_dir
	 */
	public function test_load_makes_the_secrets_api_classes_available(): void {
		$this->assertTrue( Secrets_API_Loader::load() );
		$this->assertTrue( Secrets_API_Loader::is_loaded() );

		foreach ( array( 'WP_Secret', 'WP_Secret_Version', 'WP_Secrets_Cipher', 'WP_Secrets_Key_Manager', 'WP_Secrets_Config_Key_Provider', 'WP_Secrets_Option_Store', 'WP_Secrets_Libsodium_Provider' ) as $class_name ) {
			$this->assertTrue( class_exists( $class_name, false ), $class_name );
		}
		foreach ( array( 'WP_Secrets_Provider', 'WP_Secrets_Keyring', 'WP_Secrets_Store' ) as $interface_name ) {
			$this->assertTrue( interface_exists( $interface_name, false ), $interface_name );
		}

		$this->assertTrue( function_exists( 'wp_secrets_memzero' ) );
		$this->assertTrue( function_exists( 'wp_secrets_validate_name' ) );
		$this->assertTrue( defined( 'WP_SECRETS_ERROR_INVALID_NAME' ) );

		$this->assertFalse( function_exists( 'wp_get_secret' ), 'The global API functions must not be defined by this library.' );
	}

	/**
	 * A second call is a no-op that still reports availability (the files are include_once'd).
	 *
	 * @covers ::load
	 */
	public function test_load_is_idempotent(): void {
		$this->assertTrue( Secrets_API_Loader::load() );
		$this->assertTrue( Secrets_API_Loader::load() );
	}

	/**
	 * The compat name validation matches what the store produces and rejects what the API would.
	 *
	 * @coversNothing
	 */
	public function test_compat_validate_name(): void {
		Secrets_API_Loader::load();

		$this->assertTrue( wp_secrets_validate_name( 'my-plugin/my_accounts-0123abcd' ) );
		foreach ( array( '', 'no-namespace', 'a/b/c', 'Upper/case', '-leading/key', 'ns/trailing_', str_repeat( 'a', 100 ) . '/' . str_repeat( 'b', 100 ) ) as $bad ) {
			$this->assertInstanceOf( \WP_Error::class, wp_secrets_validate_name( $bad ), $bad );
		}
	}

	/**
	 * In this project the package is in the root autoloader, so Composer's runtime API locates it.
	 *
	 * @covers ::find_via_composer
	 */
	public function test_find_via_composer_locates_the_vendored_package(): void {
		$path = Testable_Secrets_API_Loader::via_composer();

		$this->assertNotNull( $path );
		$this->assertSame( realpath( dirname( __DIR__, 2 ) . '/vendor/wordpress/secrets-api' ), realpath( $path ) );
	}

	/**
	 * The library's real location resolves through the directory walk too (the fallback when the
	 * package is not in the current autoloader, e.g. the self-contained Playground build).
	 *
	 * @covers ::find_in_parent_directories
	 */
	public function test_directory_walk_from_the_library_finds_the_project_vendor_directory(): void {
		$includes_dir = dirname( __DIR__, 2 ) . '/includes';

		$path = Testable_Secrets_API_Loader::in_parent_directories( $includes_dir );

		$this->assertSame( realpath( dirname( __DIR__, 2 ) . '/vendor/wordpress/secrets-api' ), realpath( (string) $path ) );
	}

	/**
	 * Mirrors the nested layout: the library at `{plugin}/vendor/brianhenryie/bh-wp-mailboxes/includes`
	 * and the package at `{plugin}/vendor/wordpress/secrets-api`, four levels up.
	 *
	 * @covers ::find_in_parent_directories
	 */
	public function test_directory_walk_finds_a_consuming_plugins_vendor_directory(): void {
		$plugin_dir   = $this->make_temp_dir();
		$includes_dir = $plugin_dir . '/vendor/brianhenryie/bh-wp-mailboxes/includes';
		$package_dir  = $plugin_dir . '/vendor/wordpress/secrets-api';
		mkdir( $includes_dir, 0777, true );
		$this->make_package( $package_dir );

		$this->assertSame( $package_dir, Testable_Secrets_API_Loader::in_parent_directories( $includes_dir ) );
	}

	/**
	 * The nearest match wins when both the library's own vendor directory and the consumer's have the package.
	 *
	 * @covers ::find_in_parent_directories
	 */
	public function test_directory_walk_prefers_the_nearest_vendor_directory(): void {
		$plugin_dir   = $this->make_temp_dir();
		$library_dir  = $plugin_dir . '/vendor/brianhenryie/bh-wp-mailboxes';
		$near_dir     = $library_dir . '/vendor/wordpress/secrets-api';
		$far_dir      = $plugin_dir . '/vendor/wordpress/secrets-api';
		$includes_dir = $library_dir . '/includes';
		mkdir( $includes_dir, 0777, true );
		$this->make_package( $near_dir );
		$this->make_package( $far_dir );

		$this->assertSame( $near_dir, Testable_Secrets_API_Loader::in_parent_directories( $includes_dir ) );
	}

	/**
	 * @covers ::find_in_parent_directories
	 */
	public function test_directory_walk_returns_null_when_the_package_is_absent(): void {
		$dir = $this->make_temp_dir() . '/a/b/c/includes';
		mkdir( $dir, 0777, true );

		$this->assertNull( Testable_Secrets_API_Loader::in_parent_directories( $dir ) );
	}

	/**
	 * The walk stops after five ancestors: a package six levels up is not found.
	 *
	 * @covers ::find_in_parent_directories
	 */
	public function test_directory_walk_is_bounded(): void {
		$root        = $this->make_temp_dir();
		$package_dir = $root . '/vendor/wordpress/secrets-api';
		$start       = $root . '/1/2/3/4/5/6';
		$this->make_package( $package_dir );
		mkdir( $start, 0777, true );

		$this->assertNull( Testable_Secrets_API_Loader::in_parent_directories( $start ) );
		$this->assertSame( $package_dir, Testable_Secrets_API_Loader::in_parent_directories( dirname( $start ) ), 'Five levels up is within reach.' );
	}

	/**
	 * Create the marker file the loader looks for: the provider class inside `src/wp-includes`.
	 *
	 * @param string $package_dir The fake package directory.
	 */
	protected function make_package( string $package_dir ): void {
		mkdir( $package_dir . '/src/wp-includes', 0777, true );
		file_put_contents( $package_dir . '/src/wp-includes/class-wp-secrets-libsodium-provider.php', "<?php\n" );
	}

	/**
	 * A fresh, empty directory under the system temp dir.
	 */
	protected function make_temp_dir(): string {
		$dir = sys_get_temp_dir() . '/bh-wp-mailboxes-loader-' . uniqid();
		mkdir( $dir, 0777, true );
		$this->temp_dirs[] = $dir;

		return $dir;
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $dir The directory.
	 */
	protected function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}

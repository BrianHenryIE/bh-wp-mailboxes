<?php
/**
 * WPUnit tests for loading the Secrets API feature plugin from Composer's vendor directory.
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
	 * @covers ::load
	 * @covers ::find_plugin_file
	 */
	public function test_load_makes_the_secrets_api_available(): void {
		$this->assertTrue( Secrets_API_Loader::load() );

		$this->assertTrue( function_exists( 'wp_get_secret' ) );
		$this->assertTrue( function_exists( 'wp_set_secret' ) );
		$this->assertTrue( function_exists( 'wp_delete_secret' ) );
		$this->assertTrue( class_exists( 'WP_Secret' ) );
	}

	/**
	 * A second call (the functions already exist) is a no-op that still reports availability;
	 * it must not include the plugin file again, which would redeclare its functions.
	 *
	 * @covers ::load
	 */
	public function test_load_is_idempotent(): void {
		$this->assertTrue( Secrets_API_Loader::load() );
		$this->assertTrue( Secrets_API_Loader::load() );
	}

	/**
	 * In this project the package is in the root autoloader, so Composer's runtime API locates it.
	 *
	 * @covers ::find_via_composer
	 */
	public function test_find_via_composer_locates_the_vendored_package(): void {
		$path = Testable_Secrets_API_Loader::via_composer();

		$this->assertNotNull( $path );
		$this->assertFileExists( $path );
		$this->assertSame( realpath( dirname( __DIR__, 2 ) . '/vendor/wordpress/secrets-api/secrets-api.php' ), realpath( $path ) );
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

		$this->assertSame( realpath( dirname( __DIR__, 2 ) . '/vendor/wordpress/secrets-api/secrets-api.php' ), realpath( (string) $path ) );
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
		$package_file = $plugin_dir . '/vendor/wordpress/secrets-api/secrets-api.php';
		mkdir( $includes_dir, 0777, true );
		mkdir( dirname( $package_file ), 0777, true );
		file_put_contents( $package_file, "<?php\n" );

		$this->assertSame( $package_file, Testable_Secrets_API_Loader::in_parent_directories( $includes_dir ) );
	}

	/**
	 * The nearest match wins when both the library's own vendor directory and the consumer's have the package.
	 *
	 * @covers ::find_in_parent_directories
	 */
	public function test_directory_walk_prefers_the_nearest_vendor_directory(): void {
		$plugin_dir   = $this->make_temp_dir();
		$library_dir  = $plugin_dir . '/vendor/brianhenryie/bh-wp-mailboxes';
		$near_file    = $library_dir . '/vendor/wordpress/secrets-api/secrets-api.php';
		$far_file     = $plugin_dir . '/vendor/wordpress/secrets-api/secrets-api.php';
		$includes_dir = $library_dir . '/includes';
		foreach ( array( $includes_dir, dirname( $near_file ), dirname( $far_file ) ) as $dir ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $near_file, "<?php\n" );
		file_put_contents( $far_file, "<?php\n" );

		$this->assertSame( $near_file, Testable_Secrets_API_Loader::in_parent_directories( $includes_dir ) );
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
		$root         = $this->make_temp_dir();
		$package_file = $root . '/vendor/wordpress/secrets-api/secrets-api.php';
		$start        = $root . '/1/2/3/4/5/6';
		mkdir( dirname( $package_file ), 0777, true );
		mkdir( $start, 0777, true );
		file_put_contents( $package_file, "<?php\n" );

		$this->assertNull( Testable_Secrets_API_Loader::in_parent_directories( $start ) );
		$this->assertSame( $package_file, Testable_Secrets_API_Loader::in_parent_directories( dirname( $start ) ), 'Five levels up is within reach.' );
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

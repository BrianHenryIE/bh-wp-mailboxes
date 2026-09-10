<?php
/**
 * Unit tests for loading the library's copy of the Secrets API: locating the package, including its
 * functions file, and autoloading its classes.
 *
 * Exercised against a fake package written to a temp directory ({@see Fake_Secrets_API_Loader}), since
 * the real package's files can only be included once per process.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests write a throwaway package under the system temp dir.

use Composer\InstalledVersions;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Secrets_API_Loader
 */
class Secrets_API_Loader_Unit_Test extends Unit_Testcase {

	/**
	 * The fake package's `src/wp-includes` directory for the current test.
	 *
	 * @var ?string
	 */
	protected ?string $includes_dir = null;

	/**
	 * A unique suffix so each test's fake `secrets.php` can declare its own marker function.
	 *
	 * @var string
	 */
	protected string $marker = '';

	protected function setup(): void {
		parent::setup();
		Fake_Secrets_API_Loader::reset();
		$this->marker = 'bh_wp_mailboxes_loader_test_' . uniqid();
	}

	protected function tearDown(): void {
		Fake_Secrets_API_Loader::reset();
		if ( ! is_null( $this->includes_dir ) ) {
			array_map( 'unlink', glob( $this->includes_dir . '/*' ) ?: array() );
			rmdir( $this->includes_dir );
			rmdir( dirname( $this->includes_dir ) );
			rmdir( dirname( $this->includes_dir, 2 ) );
			$this->includes_dir = null;
		}
		parent::tearDown();
	}

	/**
	 * Write a fake package: `secrets.php` declaring a marker function, and the class file in
	 * {@see Fake_Secrets_API_Loader::CLASS_MAP} declaring a uniquely named class plus a second marker
	 * function (`{marker}_class_file`), which is what tests check, since the mapped class name (an
	 * alias) can only be declared once per process.
	 *
	 * @param bool $with_class Whether to write the class file too.
	 */
	protected function make_fake_package( bool $with_class = true ): string {
		$package_dir        = sys_get_temp_dir() . '/bh-wp-mailboxes-secrets-api-' . uniqid();
		$this->includes_dir = $package_dir . '/src/wp-includes';
		mkdir( $this->includes_dir, 0777, true );

		file_put_contents(
			$this->includes_dir . '/secrets.php',
			"<?php\nfunction {$this->marker}() { return 'marker'; }\n"
		);

		if ( $with_class ) {
			$class_name = 'BH_WP_Mailboxes_Loader_Test_Class_' . uniqid();
			file_put_contents(
				$this->includes_dir . '/class-fake.php',
				"<?php\nfunction {$this->marker}_class_file() {}\nclass {$class_name} {}\nif ( ! class_exists( 'BH_WP_Mailboxes_Loader_Test_Fake_Class', false ) ) { class_alias( '{$class_name}', 'BH_WP_Mailboxes_Loader_Test_Fake_Class' ); }\n"
			);
		}

		Fake_Secrets_API_Loader::$fake_includes_dir = $this->includes_dir;

		return $this->includes_dir;
	}

	/**
	 * @covers ::load
	 * @covers ::is_loaded
	 * @covers ::autoload
	 */
	public function test_load_includes_the_functions_and_registers_the_autoloader(): void {
		$this->make_fake_package();

		$this->assertFalse( Fake_Secrets_API_Loader::is_loaded() );
		$this->assertFalse( function_exists( $this->marker ) );

		$this->assertTrue( Fake_Secrets_API_Loader::load() );

		$this->assertTrue( Fake_Secrets_API_Loader::is_loaded() );
		$this->assertTrue( function_exists( $this->marker ), 'secrets.php was included.' );
		// (Under WP_Mock's Patchwork the registered callable is reported as a closure, so registration is
		// asserted by behaviour: asking for the mapped class includes its file.)
		$this->assertFalse( function_exists( $this->marker . '_class_file' ), 'Class files are not included eagerly.' );
		Fake_Secrets_API_Loader::autoload( 'BH_WP_Mailboxes_Loader_Test_Fake_Class' );
		$this->assertTrue( function_exists( $this->marker . '_class_file' ), 'The mapped class file is included on demand.' );
	}

	/**
	 * When the package is not installed nothing is loaded or registered.
	 *
	 * @covers ::load
	 * @covers ::is_loaded
	 */
	public function test_load_fails_when_the_package_is_not_installed(): void {
		Fake_Secrets_API_Loader::$fake_includes_dir = null;
		$autoloaders_before                         = count( spl_autoload_functions() );

		$this->assertFalse( Fake_Secrets_API_Loader::load() );

		$this->assertFalse( Fake_Secrets_API_Loader::is_loaded() );
		$this->assertCount( $autoloaders_before, spl_autoload_functions(), 'No autoloader registered.' );
	}

	/**
	 * The functions file is skipped when its functions already exist (an activated, unprefixed copy),
	 * while the autoloader is still registered for any class not yet declared.
	 *
	 * @covers ::load
	 * @covers ::functions_exist
	 */
	public function test_load_skips_the_functions_file_when_the_functions_exist(): void {
		$this->make_fake_package();
		Fake_Secrets_API_Loader::$fake_functions_exist = true;

		$this->assertTrue( Fake_Secrets_API_Loader::load() );

		$this->assertFalse( function_exists( $this->marker ), 'secrets.php must not be included.' );
		Fake_Secrets_API_Loader::autoload( 'BH_WP_Mailboxes_Loader_Test_Fake_Class' );
		$this->assertTrue( function_exists( $this->marker . '_class_file' ), 'Classes are still served.' );
	}

	/**
	 * @covers ::load
	 */
	public function test_load_is_idempotent(): void {
		$this->make_fake_package();

		$this->assertTrue( Fake_Secrets_API_Loader::load() );
		$autoloaders_after_first = count( spl_autoload_functions() );

		$this->assertTrue( Fake_Secrets_API_Loader::load() );

		$this->assertCount( $autoloaders_after_first, spl_autoload_functions(), 'Registered once.' );
	}

	/**
	 * Classes outside the map are left to other autoloaders, and nothing is included before load().
	 *
	 * @covers ::autoload
	 */
	public function test_autoload_ignores_unknown_classes_and_does_nothing_before_load(): void {
		$this->make_fake_package();

		Fake_Secrets_API_Loader::autoload( 'BH_WP_Mailboxes_Loader_Test_Fake_Class' );
		$this->assertFalse( function_exists( $this->marker . '_class_file' ), 'Nothing is included before load() records the directory.' );

		Fake_Secrets_API_Loader::load();
		Fake_Secrets_API_Loader::autoload( 'Some\\Unrelated\\Class_Name' );
		$this->assertFalse( class_exists( 'Some\\Unrelated\\Class_Name', false ) );
		$this->assertFalse( function_exists( $this->marker . '_class_file' ), 'Only mapped classes are served.' );
	}

	/**
	 * The real lookup finds the vendored package through Composer's runtime API.
	 *
	 * @covers ::get_includes_dir
	 */
	public function test_get_includes_dir_finds_the_vendored_package(): void {
		$dir = Fake_Secrets_API_Loader::real_includes_dir();

		$this->assertNotNull( $dir );
		$this->assertSame( realpath( InstalledVersions::getInstallPath( 'wordpress/secrets-api' ) . '/src/wp-includes' ), realpath( $dir ) );
		$this->assertFileExists( $dir . '/secrets.php' );
	}

	/**
	 * The static class map names every class and interface file in the vendored package, with the
	 * class each file declares (the `WP` capitalisation the file names lose).
	 *
	 * @coversNothing
	 */
	public function test_class_map_matches_the_vendored_package(): void {
		$includes_dir = InstalledVersions::getInstallPath( 'wordpress/secrets-api' ) . '/src/wp-includes';

		$declared = array();
		foreach ( array_merge( (array) glob( $includes_dir . '/class-*.php' ), (array) glob( $includes_dir . '/interface-*.php' ) ) as $file ) {
			$this->assertSame( 1, preg_match( '/^\s*(?:final\s+|abstract\s+)?(?:class|interface)\s+(\w+)/m', (string) file_get_contents( (string) $file ), $matches ), (string) $file );
			$declared[ $matches[1] ] = basename( (string) $file );
		}
		ksort( $declared );

		$map = Secrets_API_Loader::CLASS_MAP;
		ksort( $map );

		$this->assertSame( $declared, $map, 'Regenerate Secrets_API_Loader::CLASS_MAP from the package.' );
	}
}

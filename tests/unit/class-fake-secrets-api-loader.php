<?php
/**
 * Test double for {@see Secrets_API_Loader} pointed at a fake package on disk.
 *
 * The loader keeps process-wide static state, so the double redeclares it (each class has its own
 * copy under late static binding) and lets the test set where the package is and whether the
 * package's functions "already exist".
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

/**
 * A loader for a fake package whose files the test writes.
 */
class Fake_Secrets_API_Loader extends Secrets_API_Loader {

	/**
	 * The fake package's single class, declared in a file the test writes.
	 */
	const CLASS_MAP = array(
		'BH_WP_Mailboxes_Loader_Test_Fake_Class' => 'class-fake.php',
	);

	/**
	 * Own copy of the loader's registered flag.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	/**
	 * Own copy of the loader's includes directory.
	 *
	 * @var ?string
	 */
	protected static ?string $includes_dir = null;

	/**
	 * Where the fake package's `src/wp-includes` is, or null to simulate "not installed".
	 *
	 * @var ?string
	 */
	public static ?string $fake_includes_dir = null;

	/**
	 * What functions_exist() reports.
	 *
	 * @var bool
	 */
	public static bool $fake_functions_exist = false;

	/**
	 * Forget everything, so each test starts from an unloaded loader.
	 */
	public static function reset(): void {
		if ( static::$registered ) {
			spl_autoload_unregister( array( static::class, 'autoload' ) );
		}
		static::$registered           = false;
		static::$includes_dir         = null;
		static::$fake_includes_dir    = null;
		static::$fake_functions_exist = false;
	}

	/**
	 * The real loader's answer, for the test that checks the vendored package is found.
	 */
	public static function real_includes_dir(): ?string {
		return parent::get_includes_dir();
	}

	protected static function get_includes_dir(): ?string {
		return static::$fake_includes_dir;
	}

	protected static function functions_exist(): bool {
		return static::$fake_functions_exist;
	}
}

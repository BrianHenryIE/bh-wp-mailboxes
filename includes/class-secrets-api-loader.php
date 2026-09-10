<?php
/**
 * Makes the WordPress Secrets API available from Composer's vendor directory.
 *
 * The Secrets API is proposed for WordPress core and is currently the `wordpress/secrets-api`
 * feature plugin, which this library depends on via Composer and always uses as its own private
 * copy: consumers prefix its class, function and constant names at build time, so it never
 * interacts with core's or an activated plugin's implementation, and the library never calls
 * `wp_get_secret()` etc. ({@see \BrianHenryIE\WP_Mailboxes\API\Secrets_Credentials_Store} uses
 * `WP_Secrets_Libsodium_Provider` directly).
 *
 * The package is located through Composer's runtime API (`composer-runtime-api` is a requirement).
 * Its `src/wp-includes/secrets.php` (constants and helper functions) is included eagerly, and an
 * autoloader is registered for the classes and interfaces declared in that directory. The plugin
 * bootstrap (`secrets-api.php`, with its core-conflict checks, hooks and drop-in loading) is never
 * included.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes;

use Composer\InstalledVersions;

/**
 * Include the Secrets API's functions and autoload its classes.
 */
class Secrets_API_Loader {

	const PACKAGE_NAME = 'wordpress/secrets-api';

	/**
	 * Whether {@see load()} has registered the autoloader in this request.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	/**
	 * Class/interface name => file, for the package's `src/wp-includes` directory; built on first use.
	 *
	 * @var ?array<string, string>
	 */
	protected static ?array $class_map = null;

	/**
	 * Ensure the Secrets API is usable: its functions included and its classes autoloadable. Idempotent.
	 *
	 * @return bool False when the package is not installed.
	 */
	public static function load(): bool {
		if ( self::$registered ) {
			return true;
		}

		$includes_dir = self::get_includes_dir();

		if ( is_null( $includes_dir ) ) {
			return false;
		}

		// Skipped when its functions already exist (an unprefixed dev environment with the feature
		// plugin active); the class autoloader below is only consulted for classes not already declared.
		if ( ! function_exists( 'wp_secrets_memzero' ) ) {
			require_once $includes_dir . '/secrets.php';
		}

		spl_autoload_register( array( self::class, 'autoload' ) );
		self::$registered = true;

		return true;
	}

	/**
	 * Whether {@see load()} has succeeded in this request.
	 */
	public static function is_loaded(): bool {
		return self::$registered;
	}

	/**
	 * Include the file declaring one of the package's classes or interfaces.
	 *
	 * @param string $class_name The fully qualified name being autoloaded.
	 */
	public static function autoload( string $class_name ): void {
		$file = self::get_class_map()[ $class_name ] ?? null;

		if ( ! is_null( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * The classes and interfaces declared in the package's `src/wp-includes` directory, keyed by name.
	 *
	 * Enumerated by reading each `class-*.php` / `interface-*.php` file's declaration rather than by
	 * inferring the name from the file name (WordPress's convention loses the `WP` capitalisation).
	 *
	 * @return array<string, string> Class name => absolute file path.
	 */
	public static function get_class_map(): array {
		if ( ! is_null( self::$class_map ) ) {
			return self::$class_map;
		}

		self::$class_map = array();

		$includes_dir = self::get_includes_dir();
		if ( is_null( $includes_dir ) ) {
			return self::$class_map;
		}

		foreach ( (array) glob( $includes_dir . '/{class,interface}-*.php', GLOB_BRACE ) as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in vendor.
			if ( 1 === preg_match( '/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $source, $matches ) ) {
				self::$class_map[ $matches[1] ] = $file;
			}
		}

		return self::$class_map;
	}

	/**
	 * The package's `src/wp-includes` directory, from Composer's runtime API, or null when not installed.
	 */
	protected static function get_includes_dir(): ?string {
		if ( ! InstalledVersions::isInstalled( self::PACKAGE_NAME ) ) {
			return null;
		}

		$install_path = InstalledVersions::getInstallPath( self::PACKAGE_NAME );

		if ( ! is_string( $install_path ) || ! is_dir( $install_path . '/src/wp-includes' ) ) {
			return null;
		}

		return $install_path . '/src/wp-includes';
	}
}

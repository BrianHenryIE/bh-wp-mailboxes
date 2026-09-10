<?php
/**
 * Makes the WordPress Secrets API's classes available from Composer's vendor directory.
 *
 * The Secrets API is proposed for WordPress core and is currently the `wordpress/secrets-api`
 * feature plugin, which this library depends on via Composer and always uses as its own private
 * copy: consumers prefix its class, function and constant names at build time, so it never
 * interacts with core's or an activated plugin's implementation, and the library never calls
 * `wp_get_secret()` etc. ({@see \BrianHenryIE\WP_Mailboxes\API\Secrets_Credentials_Store} uses
 * `WP_Secrets_Libsodium_Provider` directly).
 *
 * Loaded from the package: `src/wp-includes/secrets.php` (the constants and helper functions the
 * classes need) and the class files. The plugin bootstrap (`secrets-api.php`, with its core-conflict
 * checks, hooks and drop-in loading) is never included.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes;

use Composer\InstalledVersions;
use Throwable;

/**
 * Include the Secrets API classes.
 */
class Secrets_API_Loader {

	const PACKAGE_NAME = 'wordpress/secrets-api';

	/**
	 * The files to include, in dependency order, relative to the package's `src/wp-includes` directory.
	 */
	const FILES = array(
		'secrets.php',
		'interface-wp-secrets-provider.php',
		'interface-wp-secrets-keyring.php',
		'interface-wp-secrets-store.php',
		'class-wp-secret-version.php',
		'class-wp-secret.php',
		'class-wp-secrets-config-key-provider.php',
		'class-wp-secrets-cipher.php',
		'class-wp-secrets-key-manager.php',
		'class-wp-secrets-option-store.php',
		'class-wp-secrets-libsodium-provider.php',
	);

	/**
	 * Ensure the Secrets API's provider classes are loaded. Idempotent.
	 *
	 * @return bool True when the provider class exists afterwards.
	 */
	public static function load(): bool {
		if ( self::is_loaded() ) {
			return true;
		}

		$includes_dir = self::find_includes_dir();

		if ( is_null( $includes_dir ) ) {
			return false;
		}

		foreach ( self::FILES as $file ) {
			// `secrets.php` is skipped when its functions already exist (an unprefixed dev environment
			// with the feature plugin active): the class files carry no such guard and are not redeclared.
			if ( 'secrets.php' === $file && function_exists( 'wp_secrets_memzero' ) ) {
				continue;
			}
			require_once $includes_dir . '/' . $file;
		}

		return self::is_loaded();
	}

	/**
	 * Whether the provider class is loaded.
	 */
	public static function is_loaded(): bool {
		return class_exists( 'WP_Secrets_Libsodium_Provider', false );
	}

	/**
	 * Locate the package's `src/wp-includes` directory: ask Composer's runtime API, then look in the
	 * vendor directories above this file.
	 */
	protected static function find_includes_dir(): ?string {
		$package_dir = self::find_via_composer() ?? self::find_in_parent_directories( __DIR__ );

		return is_null( $package_dir ) ? null : $package_dir . '/src/wp-includes';
	}

	/**
	 * The package's directory as reported by Composer's runtime API, when the package is in the current autoloader.
	 */
	protected static function find_via_composer(): ?string {
		try {
			if ( class_exists( InstalledVersions::class ) && InstalledVersions::isInstalled( self::PACKAGE_NAME ) ) {
				$install_path = InstalledVersions::getInstallPath( self::PACKAGE_NAME );
				if ( is_string( $install_path ) && self::is_package_dir( $install_path ) ) {
					return $install_path;
				}
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fall through to the directory search.
			// InstalledVersions throws when asked about a package outside the current autoloader; search instead.
		}

		return null;
	}

	/**
	 * Search `{dir}/vendor/wordpress/secrets-api` in a directory and up to five of its ancestors.
	 *
	 * From `includes/class-secrets-api-loader.php` that covers the library's own root, its vendor directory
	 * when installed as a dependency, and the consuming plugin's vendor directory.
	 *
	 * @param string $directory Where to start.
	 */
	protected static function find_in_parent_directories( string $directory ): ?string {
		for ( $level = 0; $level < 6; $level++ ) {
			$candidate = $directory . '/vendor/' . self::PACKAGE_NAME;
			if ( self::is_package_dir( $candidate ) ) {
				return $candidate;
			}
			$parent = dirname( $directory );
			if ( $parent === $directory ) {
				break;
			}
			$directory = $parent;
		}

		return null;
	}

	/**
	 * Whether a directory holds the package's class files.
	 *
	 * @param string $directory Candidate package directory.
	 */
	protected static function is_package_dir( string $directory ): bool {
		return file_exists( $directory . '/src/wp-includes/class-wp-secrets-libsodium-provider.php' );
	}
}

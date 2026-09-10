<?php
/**
 * Loads the WordPress Secrets API feature plugin from Composer's vendor directory when it is not already available.
 *
 * The Secrets API (`wp_get_secret()` etc.) is proposed for WordPress core and is currently a feature
 * plugin, `wordpress/secrets-api`, which this library depends on via Composer. It is written as a plugin
 * (its bootstrap runs when its main file is included), so it is included here from wherever Composer
 * installed it, unless core or an activated copy of the plugin has already defined the functions.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes;

use Composer\InstalledVersions;
use Throwable;

/**
 * Include the Secrets API feature plugin.
 */
class Secrets_API_Loader {

	const PACKAGE_NAME = 'wordpress/secrets-api';

	/**
	 * Ensure the Secrets API functions exist, including the feature plugin's main file if needed.
	 *
	 * @return bool True when `wp_get_secret()` is available afterwards.
	 */
	public static function load(): bool {
		if ( function_exists( 'wp_get_secret' ) ) {
			return true;
		}

		$plugin_file = self::find_plugin_file();

		if ( is_null( $plugin_file ) ) {
			return false;
		}

		require_once $plugin_file;

		return function_exists( 'wp_get_secret' );
	}

	/**
	 * Locate `secrets-api.php`: ask Composer's runtime API, then look in the vendor directories above this file.
	 */
	protected static function find_plugin_file(): ?string {
		return self::find_via_composer() ?? self::find_in_parent_directories( __DIR__ );
	}

	/**
	 * The package's main file as reported by Composer's runtime API, when the package is in the current autoloader.
	 */
	protected static function find_via_composer(): ?string {
		try {
			if ( class_exists( InstalledVersions::class ) && InstalledVersions::isInstalled( self::PACKAGE_NAME ) ) {
				$install_path = InstalledVersions::getInstallPath( self::PACKAGE_NAME );
				if ( is_string( $install_path ) && file_exists( $install_path . '/secrets-api.php' ) ) {
					return $install_path . '/secrets-api.php';
				}
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fall through to the directory search.
			// InstalledVersions throws when asked about a package outside the current autoloader; search instead.
		}

		return null;
	}

	/**
	 * Search `{dir}/vendor/wordpress/secrets-api/secrets-api.php` in a directory and up to five of its ancestors.
	 *
	 * From `includes/class-secrets-api-loader.php` that covers the library's own root, its vendor directory
	 * when installed as a dependency, and the consuming plugin's vendor directory.
	 *
	 * @param string $directory Where to start.
	 */
	protected static function find_in_parent_directories( string $directory ): ?string {
		for ( $level = 0; $level < 6; $level++ ) {
			$candidate = $directory . '/vendor/' . self::PACKAGE_NAME . '/secrets-api.php';
			if ( file_exists( $candidate ) ) {
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
}

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
 * Loaded on first use by {@see \BrianHenryIE\WP_Mailboxes\API\Secrets_Credentials_Store}, not at
 * bootstrap, so a request that never reads or writes credentials never touches the package. The
 * package is located through Composer's runtime API (`composer-runtime-api` is a requirement); its
 * `src/wp-includes/secrets.php` (constants and helper functions) is then included, and an autoloader
 * is registered for the classes and interfaces in that directory ({@see self::CLASS_MAP}). The plugin
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
	 * The classes and interfaces declared in the package's `src/wp-includes` directory, keyed by name.
	 *
	 * Listed explicitly (rather than derived from the file names, which lose the `WP` capitalisation,
	 * or enumerated at runtime); regenerate when the package adds a class. The wpunit test compares
	 * it against the vendored files.
	 *
	 * @var array<string, string> Class name => file name.
	 */
	const CLASS_MAP = array(
		'WP_Secret'                      => 'class-wp-secret.php',
		'WP_Secret_Version'              => 'class-wp-secret-version.php',
		'WP_Secrets_Broken_Keyring'      => 'class-wp-secrets-broken-keyring.php',
		'WP_Secrets_Broken_Provider'     => 'class-wp-secrets-broken-provider.php',
		'WP_Secrets_Broken_Store'        => 'class-wp-secrets-broken-store.php',
		'WP_Secrets_Cipher'              => 'class-wp-secrets-cipher.php',
		'WP_Secrets_Config_Key_Provider' => 'class-wp-secrets-config-key-provider.php',
		'WP_Secrets_Key_Manager'         => 'class-wp-secrets-key-manager.php',
		'WP_Secrets_Keyring'             => 'interface-wp-secrets-keyring.php',
		'WP_Secrets_Libsodium_Provider'  => 'class-wp-secrets-libsodium-provider.php',
		'WP_Secrets_Option_Store'        => 'class-wp-secrets-option-store.php',
		'WP_Secrets_Provider'            => 'interface-wp-secrets-provider.php',
		'WP_Secrets_Store'               => 'interface-wp-secrets-store.php',
	);

	/**
	 * The package's `src/wp-includes` directory, recorded by {@see load()} for {@see autoload()}.
	 *
	 * @var ?string
	 */
	protected static ?string $includes_dir = null;

	/**
	 * Ensure the Secrets API is usable: its functions included and its classes autoloadable. Idempotent.
	 *
	 * @return bool False when the package is not installed.
	 */
	public static function load(): bool {
		if ( static::$registered ) {
			return true;
		}

		$includes_dir = static::get_includes_dir();

		if ( is_null( $includes_dir ) ) {
			return false;
		}

		// Skipped when its functions already exist (an unprefixed dev environment with the feature
		// plugin active); the class autoloader below is only consulted for classes not already declared.
		if ( ! static::functions_exist() ) {
			require_once $includes_dir . '/secrets.php';
		}

		static::$includes_dir = $includes_dir;
		spl_autoload_register( array( static::class, 'autoload' ) );
		static::$registered = true;

		return true;
	}

	/**
	 * Whether {@see load()} has run and succeeded in this request.
	 */
	public static function is_loaded(): bool {
		return static::$registered;
	}

	/**
	 * Include the file declaring one of the package's classes or interfaces.
	 *
	 * @param string $class_name The fully qualified name being autoloaded.
	 */
	public static function autoload( string $class_name ): void {
		$file = static::CLASS_MAP[ $class_name ] ?? null;

		if ( ! is_null( $file ) && ! is_null( static::$includes_dir ) ) {
			require_once static::$includes_dir . '/' . $file;
		}
	}

	/**
	 * Whether the package's functions are already defined (by an activated, unprefixed copy).
	 */
	protected static function functions_exist(): bool {
		return function_exists( 'wp_secrets_memzero' );
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

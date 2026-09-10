<?php
/**
 * Test double exposing {@see Secrets_API_Loader}'s protected lookups.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

/**
 * Exposes the loader's protected lookups.
 */
class Testable_Secrets_API_Loader extends Secrets_API_Loader {

	public static function via_composer(): ?string {
		return self::find_via_composer();
	}

	public static function in_parent_directories( string $directory ): ?string {
		return self::find_in_parent_directories( $directory );
	}
}

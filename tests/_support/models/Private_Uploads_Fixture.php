<?php
/**
 * A real bh-wp-private-uploads API for wpunit tests: files are written to disk and posts are created.
 *
 * Files land in `{uploads}/bh-wp-mailboxes-test-attachments/`, outside the test database transaction, so
 * tests delete them afterwards (see {@see self::delete_files()}).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads_API;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\Private_Uploads_Settings_Trait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Private_Uploads_Fixture {

	/**
	 * A real private-uploads API writing to its own test subdirectory.
	 *
	 * @param ?LoggerInterface $logger PSR-3 logger.
	 */
	public static function make( ?LoggerInterface $logger = null ): Private_Uploads_API {

		$settings = new class() implements Private_Uploads_Settings_Interface {
			use Private_Uploads_Settings_Trait;

			public function get_plugin_slug(): string {
				return 'bh-wp-mailboxes-test';
			}

			public function get_uploads_subdirectory_name(): string {
				return 'bh-wp-mailboxes-test-attachments';
			}
		};

		return new Private_Uploads_API( $settings, $logger ?? new NullLogger() );
	}

	/**
	 * Delete the files of the given attachment posts.
	 *
	 * @param array<int>|null $attachment_ids The attachment post ids.
	 */
	public static function delete_files( ?array $attachment_ids ): void {
		foreach ( $attachment_ids ?? array() as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( is_string( $file ) && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}

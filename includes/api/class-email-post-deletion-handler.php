<?php
/**
 * Removes an email's attachments (posts and files) when the email post is permanently deleted.
 *
 * Hooked into WordPress's own deletion lifecycle rather than into the library's delete command, so every
 * deletion path behaves identically: the library's `delete_local_email_post()`, the cron purge, wp-admin's
 * "Delete permanently", WP-CLI, or another plugin calling `wp_delete_post()`.
 *
 * Trash policy: `before_delete_post` does not fire on trashing, only on permanent deletion. A trashed
 * email keeps its attachments so that restoring it restores everything; cleanup happens when the trash
 * is emptied (or the post is force-deleted).
 *
 * Attachments are posts of the private-uploads post type parented to the email (not core `attachment`
 * posts), so core's own child-attachment cleanup does not apply to them; the file is deleted here
 * explicitly. Comments (the email log notes) and post meta are already deleted by `wp_delete_post()`.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Post;

/**
 * Cascades the permanent deletion of an email post to its attachment posts and files.
 */
class Email_Post_Deletion_Handler {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Provides the emails post type this handler is scoped to.
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Delete the attachment posts and files of an email that is about to be permanently deleted.
	 *
	 * @hooked before_delete_post
	 *
	 * @param int      $post_id The post being deleted.
	 * @param ?WP_Post $post    The post object (WordPress 5.5+ passes it; looked up otherwise).
	 */
	public function delete_attachments( int $post_id, ?WP_Post $post = null ): void {
		$post ??= get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || $post->post_type !== $this->settings->get_emails_cpt_underscored_20() ) {
			return;
		}

		$attachment_ids = $this->get_attachment_ids( $post_id );

		foreach ( $attachment_ids as $attachment_id ) {
			// The file path is only available while the attachment post (and its meta) still exists.
			$file = get_attached_file( $attachment_id );

			if ( false === wp_delete_post( $attachment_id, true ) ) {
				$this->logger->warning( "Failed to delete attachment post {$attachment_id} of email {$post_id}." );
				continue;
			}

			if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		if ( count( $attachment_ids ) > 0 ) {
			$this->logger->debug( sprintf( 'Deleted %d attachment(s) of email %d.', count( $attachment_ids ), $post_id ) );
		}
	}

	/**
	 * The email's attachment posts: those recorded in its `attachment_ids` meta, plus any post of any
	 * type parented to it (so nothing is orphaned if the meta is out of date).
	 *
	 * @param int $post_id The email post.
	 *
	 * @return int[]
	 */
	protected function get_attachment_ids( int $post_id ): array {
		$ids = array();

		$raw = get_post_meta( $post_id, 'attachment_ids', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			foreach ( (array) json_decode( $raw ) as $id ) {
				if ( is_int( $id ) ) {
					$ids[] = $id;
				}
			}
		}

		// Every post parented to the email, whatever its type or status (attachment posts have the internal
		// `inherit` status and a post type that may not be registered on this request).
		global $wpdb;
		/**
		 * The WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot select unregistered post types or internal statuses.
		$children = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_parent = %d', $wpdb->posts, $post_id ) );
		foreach ( $children as $child_id ) {
			$ids[] = (int) $child_id;
		}

		return array_values( array_unique( $ids ) );
	}
}

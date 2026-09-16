<?php
/**
 * Cascades an email post's trash, restore and permanent deletion to its attachments.
 *
 * Hooked into WordPress's own post lifecycle rather than into the library's commands, so every path
 * behaves identically: the library's `trash_local_email_post()` / `delete_local_email_post()`, the cron
 * purge, wp-admin's row actions and "Empty Trash", WP-CLI, or another plugin calling `wp_trash_post()` /
 * `wp_delete_post()`.
 *
 * - Trashing an email trashes its attachment posts, so they drop out of the private-uploads listing
 *   with it (files stay on disk); restoring the email restores them to their previous status.
 * - Permanent deletion (`before_delete_post`, which does not fire on trashing) deletes the attachment
 *   posts, trashed or not, and their files.
 *
 * Attachments are posts of the private-uploads post type parented to the email (not core `attachment`
 * posts), so core's own child-attachment cleanup does not apply to them; the file is deleted here
 * explicitly. Comments (the email log notes) and post meta are handled by core on both trash and delete.
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
 * Cascades an email post's trash, restore and permanent deletion to its attachment posts (and files).
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
	 * Trash the attachment posts of an email that has just been trashed.
	 *
	 * @hooked trashed_post
	 *
	 * @param int $post_id The post that was trashed.
	 */
	public function trash_attachments( int $post_id ): void {
		if ( ! $this->is_email( $post_id ) ) {
			return;
		}

		foreach ( $this->get_attachment_ids( $post_id ) as $attachment_id ) {
			if ( 'trash' === get_post_status( $attachment_id ) ) {
				continue;
			}
			if ( false === wp_trash_post( $attachment_id ) ) {
				$this->logger->warning( "Failed to trash attachment post {$attachment_id} of email {$post_id}." );
			}
		}
	}

	/**
	 * Restore the attachment posts of an email that has just been restored from the trash.
	 *
	 * @hooked untrashed_post
	 *
	 * @param int $post_id The post that was restored.
	 */
	public function untrash_attachments( int $post_id ): void {
		if ( ! $this->is_email( $post_id ) ) {
			return;
		}

		foreach ( $this->get_attachment_ids( $post_id ) as $attachment_id ) {
			if ( 'trash' !== get_post_status( $attachment_id ) ) {
				continue;
			}
			if ( false === wp_untrash_post( $attachment_id ) ) {
				$this->logger->warning( "Failed to restore attachment post {$attachment_id} of email {$post_id}." );
			}
		}
	}

	/**
	 * Restore an attachment post to the status it had before it was trashed (`inherit`), not WordPress's
	 * default `draft` for non-`attachment` post types.
	 *
	 * @hooked wp_untrash_post_status
	 *
	 * @param string $new_status      The status WordPress is about to set.
	 * @param int    $post_id         The post being restored.
	 * @param string $previous_status Its status before it was trashed.
	 */
	public function restore_attachment_status_on_untrash( string $new_status, int $post_id, string $previous_status ): string {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) || 0 === $post->post_parent || ! $this->is_email( $post->post_parent ) ) {
			return $new_status;
		}

		return $previous_status;
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
		if ( ! $this->is_email( $post_id, $post ) ) {
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
	 * Whether the post is one of this mailbox's emails.
	 *
	 * @param int      $post_id The post.
	 * @param ?WP_Post $post    The post object when the caller already has it.
	 */
	protected function is_email( int $post_id, ?WP_Post $post = null ): bool {
		$post ??= get_post( $post_id );

		return $post instanceof WP_Post && $post->post_type === $this->settings->get_emails_cpt_underscored_20();
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

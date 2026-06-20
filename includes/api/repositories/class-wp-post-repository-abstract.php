<?php
/**
 * Abstract base class for WordPress post repositories.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract;
use Exception;

/**
 * Subclasses should implement: (no generics in PHP!)
 * - save_new( ...$args ): Saved_Post
 * - find_by_post_id( int $post_id ): ?T
 * - query( WP_Post_Query_Abstract $query ): array<T>
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string, meta_input?:array<string,mixed>}
 */
abstract class WP_Post_Repository_Abstract {

	/**
	 * Add a new post from a query object. Returns the post ID.
	 *
	 * Removes WordPress's filters on `post_content` so we can use it to save data.
	 *
	 * @see wp_insert_post()
	 *
	 * @param WP_Post_Query_Abstract $query A stronly typed class that serializes to a WordPress query args array.
	 *
	 * @throws Exception When WordPress fails to create the post.
	 */
	protected function insert( WP_Post_Query_Abstract $query ): int {
		/**
		 * The query array for wp_insert_post.
		 *
		 * @var WpUpdatePostArray $args
		 */
		$args = $query->to_wp_post_array();

		$filter_name = 'content_save_pre';
		/**
		 * The global WordPress filter hooks.
		 *
		 * @var \WP_Hook[] $wp_filter
		 */
		global $wp_filter;
		$hook             = $wp_filter[ $filter_name ];
		$callbacks_before = $hook->callbacks;
		/**
		 * Avoid modifying the original email content during save. Otherwise, the Message-id header value is removed
		 * and parsing the email fails later.
		 *
		 * The following were removed in WordPress 7.0:  `wp_strip_custom_css_from_blocks`,
		 * `wp_filter_global_styles_post`, `convert_invalid_entities`, `wp_filter_post_kses`.
		 */
		$hook->callbacks = array();

		$post_id = wp_insert_post( $args, true );

		$hook->callbacks = $callbacks_before;

		if ( is_wp_error( $post_id ) ) {
			// TODO Log.
			throw new Exception( 'WordPress failed to create new post.' );
		}

		return $post_id;
	}

	/**
	 * Add a log message for each update/modification. This is saved as a WordPress comment.
	 *
	 * The idea is that plugins consuming this library can annotate the email, e.g. link the WooCommerce order it was
	 * matched to, to make debugging easy.
	 *
	 * @param Saved_Post          $saved_post The email account or email to attach the message to.
	 * @param string              $message The message to log. Will be sanitized with wp_kses_post.
	 * @param bool                $is_internal Was the message added by the library's automations or by an explicit action.
	 * @param array<string,mixed> $meta List of values changed.
	 * @param string              $level The log level: one of `info`, `notice`, `warning`, `error`. Reversible
	 *                                   changes are `info`; intentional irreversible changes are `notice`.
	 */
	public function log( Saved_Post $saved_post, string $message, bool $is_internal = false, array $meta = array(), string $level = 'info' ): void {

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'    => $saved_post->get_post_id(),
				'comment_content'    => wp_kses_post( $message ),
				'comment_agent'      => 'bh-wp-mailboxes',
				'comment_type'       => 'bh_email_log',
				'comment_author'     => 'bh-wp-mailboxes',
				'comment_author_url' => '',
				'user_id'            => get_current_user_id(),
				'comment_approved'   => 1,
			)
		);

		if ( is_int( $comment_id ) ) {
			add_comment_meta( $comment_id, 'bh_email_log_level', sanitize_key( $level ) );
		}
	}
}

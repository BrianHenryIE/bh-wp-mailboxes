<?php
/**
 * Abstract base class for WordPress post repositories.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract;

/**
 * Subclasses should implement: (no generics in PHP!)
 * - save_new( ...$args ): Saved_Post
 * - find_by_post_id( int $post_id ): ?T
 * - query( WP_Post_Query_Abstract $query ): array<T>
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string, meta_input?:array<string,mixed>}
 */
abstract class WP_Post_Repository_Abstract {

	protected function insert( WP_Post_Query_Abstract $query ): int {
		/**
		 * The query array for wp_insert_post.
		 *
		 * @var WpUpdatePostArray $args
		 */
		$args = $query->to_query_array();

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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CPT slug, not user input.
			throw new \Exception( 'WordPress failed to create new post.' );
		}

		return $post_id;
	}
}

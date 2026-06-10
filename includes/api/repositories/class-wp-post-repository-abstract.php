<?php
/**
 * Abstract base class for WordPress post repositories.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

/**
 * Subclasses should implement: (no generics in PHP!)
 * - save_new( ...$args ): Saved_Post
 * - find_by_post_id( int $post_id ): ?T
 * - query( WP_Post_Query_Abstract $query ): array<T>
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string, meta_input?:array<string,mixed>}
 */
abstract class WP_Post_Repository_Abstract {

}

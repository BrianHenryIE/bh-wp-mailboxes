<?php
/**
 * Interface for objects backed by a WordPress post.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

interface Saved_Post {
	/**
	 * Returns the WordPress post ID.
	 */
	public function get_post_id(): int;

	/**
	 * The wp_posts table post_type.
	 */
	public function get_post_type(): string;
}

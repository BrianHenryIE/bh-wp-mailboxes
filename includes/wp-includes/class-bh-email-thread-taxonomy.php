<?php
/**
 * A custom taxonomy grouping emails into conversation threads.
 *
 * Each emails post type gets its own taxonomy (`{$post_type}_thread`), so two mailboxes never share
 * threads. A term is a thread; every email post is assigned exactly one term. `post_parent` already
 * links an email to its account, which is why threads are not modelled as a post hierarchy.
 *
 * The taxonomy is hidden from the admin UI and REST: it is an index, not editable data. The linking
 * logic (which term an email belongs to, merging threads) is in {@see Email_Thread_Linker}.
 *
 * @see https://developer.wordpress.org/plugins/taxonomies/working-with-custom-taxonomies/
 * @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.4
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Term;

/**
 * Registers the email thread taxonomy and keeps it free of empty terms.
 */
class BH_Email_Thread_Taxonomy {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param string           $post_type The emails CPT slug this taxonomy groups.
	 * @param ?LoggerInterface $logger    PSR-3 logger.
	 */
	public function __construct(
		protected string $post_type,
		?LoggerInterface $logger = null
	) {
		$this->setLogger( $logger ?? new NullLogger() );
	}

	/**
	 * The taxonomy name for an emails post type.
	 *
	 * Taxonomy names are limited to 32 characters; post types are limited to 20, so the suffix fits.
	 *
	 * @param string $post_type The emails CPT slug.
	 *
	 * @return non-empty-string
	 */
	public static function taxonomy_name_for_post_type( string $post_type ): string {
		return "{$post_type}_thread";
	}

	/**
	 * The taxonomy name for this instance's emails post type.
	 *
	 * @return non-empty-string
	 */
	public function get_taxonomy_name(): string {
		return self::taxonomy_name_for_post_type( $this->post_type );
	}

	/**
	 * Register the taxonomy with WordPress.
	 *
	 * Safe to call more than once: the linker calls it when the taxonomy is not yet registered (e.g. an
	 * email saved before `init`, or in tests), so term operations never fail with `invalid_taxonomy`.
	 *
	 * @hooked init
	 */
	public function register_taxonomy(): void {

		$taxonomy = $this->get_taxonomy_name();

		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$args = array(
			'description'           => __( 'Groups emails into conversation threads.', 'bh-wp-mailboxes' ),
			'labels'                => array(
				'name'          => __( 'Email threads', 'bh-wp-mailboxes' ),
				'singular_name' => __( 'Email thread', 'bh-wp-mailboxes' ),
			),
			'public'                => false,
			'publicly_queryable'    => false,
			'hierarchical'          => false,
			'show_ui'               => false,
			'show_in_menu'          => false,
			'show_in_nav_menus'     => false,
			'show_in_rest'          => false,
			'show_tagcloud'         => false,
			'show_in_quick_edit'    => false,
			'show_admin_column'     => false,
			'rewrite'               => false,
			'query_var'             => false,
			// Core's default callback only counts `publish` posts; emails use their own statuses.
			'update_count_callback' => '_update_generic_term_count',
		);

		$result = register_taxonomy( $taxonomy, array( $this->post_type ), $args );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Failed to register email thread taxonomy {taxonomy}: {error}',
				array(
					'taxonomy' => $taxonomy,
					'error'    => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Delete thread terms that no longer have any emails.
	 *
	 * Fires when an email is permanently deleted (core removes its term relationships first) and when
	 * the linker moves emails out of a thread during a merge, so merged-away threads clean themselves up.
	 *
	 * @hooked deleted_term_relationships
	 *
	 * @param int    $object_id The post whose relationships were removed.
	 * @param int[]  $tt_ids    The term taxonomy ids removed.
	 * @param string $taxonomy  The taxonomy the relationships belonged to.
	 */
	public function delete_empty_terms( int $object_id, array $tt_ids, string $taxonomy ): void {

		if ( $taxonomy !== $this->get_taxonomy_name() ) {
			return;
		}

		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );
			if ( ! ( $term instanceof WP_Term ) ) {
				continue;
			}

			$remaining = get_objects_in_term( $term->term_id, $taxonomy );
			if ( is_wp_error( $remaining ) || array() !== $remaining ) {
				continue;
			}

			$result = wp_delete_term( $term->term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				$this->logger->warning(
					'Failed to delete empty email thread term {term_id}: {error}',
					array(
						'term_id' => $term->term_id,
						'post_id' => $object_id,
						'error'   => $result->get_error_message(),
					)
				);
			}
		}
	}
}

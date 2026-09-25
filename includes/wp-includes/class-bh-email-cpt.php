<?php
/**
 * A custom post type for storing emails.
 *
 * @see https://developer.wordpress.org/plugins/post-types/registering-custom-post-types/
 * "You must call register_post_type() before the admin_init hook and after the after_setup_theme hook. A good hook to use is the init action hook."
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Error;
use WP_Post;

/**
 * Registers the email custom post type and its post statuses.
 */
class BH_Email_CPT {

	use LoggerAwareTrait;

	/**
	 * Constructor
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings for bh-wp-mailboxes.
	 * @param LoggerInterface                    $logger PSR logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
	}

	/**
	 *
	 * "Make sure your custom post type identifier does not exceed 20 characters"
	 *
	 * @hooked init
	 */
	public function register_cpt(): void {

		$post_type = $this->settings->get_emails_cpt_underscored_20();

		$labels = array(
			'name'                     => $this->settings->get_emails_cpt_friendly_name(),
			'singular_name'            => __( 'Email', 'bh-wp-mailboxes' ),
			'add_new'                  => __( 'Add New', 'bh-wp-mailboxes' ),
			'add_new_item'             => __( 'Add New Email', 'bh-wp-mailboxes' ),
			'edit_item'                => __( 'Email', 'bh-wp-mailboxes' ),
			'new_item'                 => __( 'New Email', 'bh-wp-mailboxes' ),
			'view_item'                => __( 'View Email', 'bh-wp-mailboxes' ),
			'view_items'               => __( 'View Emails', 'bh-wp-mailboxes' ),
			'search_items'             => __( 'Search Emails', 'bh-wp-mailboxes' ),
			'not_found'                => __( 'No emails found.', 'bh-wp-mailboxes' ),
			'not_found_in_trash'       => __( 'No emails found in Trash.', 'bh-wp-mailboxes' ),
			'parent_item_colon'        => __( 'Parent Email:', 'bh-wp-mailboxes' ),
			'all_items'                => __( 'Emails', 'bh-wp-mailboxes' ),
			'archives'                 => __( 'Emails', 'bh-wp-mailboxes' ),
			'attributes'               => __( 'Email Attributes', 'bh-wp-mailboxes' ),
			'insert_into_item'         => __( 'Insert into email', 'bh-wp-mailboxes' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this email', 'bh-wp-mailboxes' ),
			'featured_image'           => __( 'Featured image', 'bh-wp-mailboxes' ),
			'set_featured_image'       => __( 'Set featured image', 'bh-wp-mailboxes' ),
			'remove_featured_image'    => __( 'Remove featured image', 'bh-wp-mailboxes' ),
			'use_featured_image'       => __( 'Use as featured image', 'bh-wp-mailboxes' ),
			'filter_items_list'        => __( 'Filter emails list', 'bh-wp-mailboxes' ),
			'filter_by_date'           => __( 'Filter by date', 'bh-wp-mailboxes' ),
			'items_list_navigation'    => __( 'Emails list navigation', 'bh-wp-mailboxes' ),
			'items_list'               => __( 'Emails list', 'bh-wp-mailboxes' ),
			'item_published'           => __( 'Email published.', 'bh-wp-mailboxes' ),
			'item_published_privately' => __( 'Email published privately.', 'bh-wp-mailboxes' ),
			'item_reverted_to_draft'   => __( 'Email reverted to draft.', 'bh-wp-mailboxes' ),
			'item_scheduled'           => __( 'Email scheduled.', 'bh-wp-mailboxes' ),
			'item_updated'             => __( 'Email updated.', 'bh-wp-mailboxes' ),
			'item_link'                => __( 'Email Link', 'bh-wp-mailboxes' ),
			'item_link_description'    => __( 'A link to an email.', 'bh-wp-mailboxes' ),
			'menu_name'                => __( 'Emails', 'bh-wp-mailboxes' ),
			'name_admin_bar'           => __( 'Email', 'bh-wp-mailboxes' ),
		);

		$args = array(
			'description'         => __( 'Store copies of emails in WordPress', 'bh-wp-mailboxes' ),
			'labels'              => $labels,
			'has_archive'         => false,
			'rewrite'             => array( 'slug' => sanitize_title( $this->settings->get_emails_cpt_friendly_name() ) ),
			'supports'            => array(
				'title',
				'comments',
			),
			'public'              => false, // This is required to have the edit.php page.
			'show_ui'             => true,
			'menu_position'       => 25,
			'show_in_menu'        => false,
			'exclude_from_search' => true,
			// Never exposed through core's posts controller: the REST ingress and the library's own routes register themselves.
			'show_in_rest'        => false,
			// Mailbox-scoped capabilities (`edit_{cpt}`, `edit_others_{cpt}s`, … and per-post meta caps), mapped to a base
			// capability by Mailbox_Capabilities::map_meta_cap(); two mailboxes get two disjoint capability sets.
			'capability_type'     => $post_type,
			'map_meta_cap'        => true,
		);

		if ( post_type_exists( $post_type ) ) {
			// Post type names are the friendly name truncated to 20 characters, so two mailboxes can collide — and would
			// then share one capability set. Registering again replaces the earlier registration.
			$this->logger->error( "Post type {$post_type} is already registered; two mailboxes cannot share a post type." );
		}

		/**
		 * Result of registering the post type.
		 *
		 * @var \WP_Post_Type|WP_Error $registered_post_type
		 */
		$registered_post_type = register_post_type( $post_type, $args );

		// TODO: throw an exception... if this fails, nothing here will really work.
		if ( is_wp_error( $registered_post_type ) ) {
			/**
			 * The error from post type registration.
			 *
			 * @var WP_Error $registered_post_type
			 */
			$this->logger->error( $registered_post_type->get_error_message() );
		}
	}

	/**
	 * Forget the account's cached per-status email counts when one of its emails changes status
	 * (including to/from trash), as core's `_transition_post_status()` does for `wp_count_posts()`.
	 *
	 * @hooked transition_post_status
	 * @see Email_WP_Post_Repository::count_by_status_for_account_email()
	 *
	 * @param string  $new_status The status the post is moving to.
	 * @param string  $old_status The status it had.
	 * @param WP_Post $post       The post.
	 */
	public function clear_account_counts_cache_on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}
		$this->clear_account_counts_cache( $post );
	}

	/**
	 * Forget the account's cached per-status email counts when one of its emails is permanently deleted.
	 *
	 * @hooked deleted_post
	 *
	 * @param int     $post_id The deleted post's ID.
	 * @param WP_Post $post    The deleted post.
	 */
	public function clear_account_counts_cache_on_delete( int $post_id, WP_Post $post ): void {
		$this->clear_account_counts_cache( $post );
	}

	/**
	 * Clear the counts cache for the post's account, if the post is one of this mailbox's emails.
	 *
	 * @param WP_Post $post The post.
	 */
	protected function clear_account_counts_cache( WP_Post $post ): void {
		if ( $post->post_type !== $this->settings->get_emails_cpt_underscored_20() ) {
			return;
		}
		Email_WP_Post_Repository::clear_status_counts_cache( $post->post_type, (int) $post->post_parent );
	}

	/**
	 * Restore a trashed email to the status it had before trashing (new / processed / saved), not WordPress's
	 * default `draft`, which the emails list does not show.
	 *
	 * @hooked wp_untrash_post_status
	 *
	 * @param string $new_status      The status WordPress would restore to (`draft`).
	 * @param int    $post_id         The post being restored.
	 * @param string $previous_status Its status before it was trashed.
	 */
	public function restore_status_on_untrash( string $new_status, int $post_id, string $previous_status ): string {
		if ( get_post_type( $post_id ) !== $this->settings->get_emails_cpt_underscored_20() ) {
			return $new_status;
		}

		return in_array( $previous_status, array( 'bh_email_new', 'bh_email_processed', 'bh_email_saved' ), true ) ? $previous_status : $new_status;
	}

	/**
	 * Register custom post statuses for emails.
	 *
	 * - bh_email_new:       Freshly downloaded, not yet acted on.
	 * - bh_email_processed: Has been processed by the plugin/hook.
	 * - bh_email_saved:     Explicitly kept; exempt from automatic cron deletion.
	 *
	 * @hooked init
	 */
	public function register_post_statuses(): void {

		register_post_status(
			'bh_email_new',
			array(
				'label'                     => _x( 'New', 'email status' ),
				'public'                    => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count of emails with this status */
				'label_count'               => _n_noop( 'New <span class="count">(%s)</span>', 'New <span class="count">(%s)</span>' ),
			)
		);

		register_post_status(
			'bh_email_processed',
			array(
				'label'                     => _x( 'Processed', 'email status' ),
				'public'                    => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count of emails with this status */
				'label_count'               => _n_noop( 'Processed <span class="count">(%s)</span>', 'Processed <span class="count">(%s)</span>' ),
			)
		);

		register_post_status(
			'bh_email_saved',
			array(
				'label'                     => _x( 'Saved', 'email status' ),
				'public'                    => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count of emails with this status */
				'label_count'               => _n_noop( 'Saved <span class="count">(%s)</span>', 'Saved <span class="count">(%s)</span>' ),
			)
		);
	}

	/**
	 * Make email cpt immutable.
	 *
	 * Restore original title and content to prevent edits to immutable email fields.
	 *
	 * @hooked wp_insert_post_data (priority 10, accepted_args 2)
	 *
	 * @param array<string, mixed> $data    Sanitised post data about to be inserted.
	 * @param array<string, mixed> $postarr Raw post data from the edit form.
	 *
	 * @return array<string, mixed>
	 */
	public function prevent_content_edits( array $data, array $postarr ): array {

		$bh_email_post_type = $this->settings->get_emails_cpt_underscored_20();

		$post_type = $data['post_type'] ?? '';
		if ( $bh_email_post_type !== $post_type ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) && is_numeric( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( 0 === $post_id ) {
			return $data;
		}

		$original = get_post( $post_id );
		if ( ! ( $original instanceof WP_Post ) ) {
			return $data;
		}

		$data['post_title']   = $original->post_title;
		$data['post_content'] = $original->post_content;

		return $data;
	}

	/**
	 * Disable autosave on the email edit screen.
	 *
	 * Emails are immutable copies of received messages, so there is nothing to autosave; dequeuing the
	 * script prevents pointless autosave/heartbeat requests against these posts.
	 *
	 * @hooked admin_enqueue_scripts
	 */
	public function disable_autosave(): void {

		$screen = get_current_screen();
		if ( is_null( $screen ) ) {
			return;
		}

		if ( $this->settings->get_emails_cpt_underscored_20() !== $screen->post_type ) {
			return;
		}

		wp_dequeue_script( 'autosave' );
	}
}

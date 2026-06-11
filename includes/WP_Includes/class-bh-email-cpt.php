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

		/**
		 * Result of registering the post type.
		 *
		 * @var \WP_Post_Type|WP_Error $registered_post_type
		 */
		$registered_post_type = register_post_type(
			$post_type,
			array(
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
				// 'capabilities'        => array( // TODO: right now only admins can see the emails?
				// 'publish_posts'       => 'update_core',
				// 'edit_others_posts'   => 'update_core',
				// 'delete_posts'        => 'update_core',
				// 'delete_others_posts' => 'update_core',
				// 'read_private_posts'  => 'update_core',
				// 'edit_post'           => 'edit_posts',
				// 'delete_post'         => 'update_core',
				// 'read_post'           => 'edit_posts',
				// ),
				'menu_position'       => 25,
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
			)
		);

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

		$post_id = (int) ( $postarr['ID'] ?? 0 );
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
}

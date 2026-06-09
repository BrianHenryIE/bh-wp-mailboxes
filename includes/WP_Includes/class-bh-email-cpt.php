<?php
/**
 * A custom post type for storing emails.
 *
 * @see https://developer.wordpress.org/plugins/post-types/registering-custom-post-types/
 * "You must call register_post_type() before the admin_init hook and after the after_setup_theme hook. A good hook to use is the init action hook."
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Taxonomy;
use WP_Error;

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

		$post_type = $this->settings->get_cpt_underscored_20();

		$labels = array(
			'name'                     => $this->settings->get_cpt_friendly_name(),
			'singular_name'            => 'Email',
			'add_new'                  => 'Add New',
			'add_new_item'             => 'Add New Email',
			'edit_item'                => 'Email',
			'new_item'                 => 'New Email',
			'view_item'                => 'View Email',
			'view_items'               => 'View Emails',
			'search_items'             => 'Search Emails',
			'not_found'                => 'No emails found.',
			'not_found_in_trash'       => 'No emails found in Trash.',
			'parent_item_colon'        => null,
			'all_items'                => 'Emails',
			'archives'                 => 'Emails',
			'attributes'               => 'Email Attributes',
			'insert_into_item'         => 'Insert into email',
			'uploaded_to_this_item'    => 'Uploaded to this email',
			'featured_image'           => 'Featured image',
			'set_featured_image'       => 'Set featured image',
			'remove_featured_image'    => 'Remove featured image',
			'use_featured_image'       => 'Use as featured image',
			'filter_items_list'        => 'Filter emails list',
			'filter_by_date'           => 'Filter by date',
			'items_list_navigation'    => 'Emails list navigation',
			'items_list'               => 'Emails list',
			'item_published'           => 'Email published.',
			'item_published_privately' => 'Email published privately.',
			'item_reverted_to_draft'   => 'Email reverted to draft.',
			'item_scheduled'           => 'Email scheduled.',
			'item_updated'             => 'Email updated.',
			'item_link'                => 'Email Link',
			'item_link_description'    => 'A link to an email.',
			'menu_name'                => 'Emails',
			'name_admin_bar'           => 'Email',
		);

		/** @var \WP_Post_Type|WP_Error  $registered_post_type */
		$registered_post_type = register_post_type(
			$post_type,
			array(
				'description'         => 'Store copies of emails in WordPress',
				'labels'              => $labels,
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => sanitize_title( $this->settings->get_cpt_friendly_name() ) ),
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
				'menu_position'       => null,
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
			)
		);

		// TODO: throw an exception... if this fails, nothing here will really work.
		if ( is_wp_error( $registered_post_type ) ) {
			/** @var WP_Error $registered_post_type */
			$this->logger->error( $registered_post_type->get_error_message() );
		}
	}

	/**
	 * All emails are saved to the same CPT, and each mailbox is given its own taxonomy, for filtering.
	 *
	 * @hooked init
	 */
	public function register_mailboxes_taxonomy(): void {

		// TODO: Make this unique to the plugin.
		$taxonomy_name = 'bh-wp-mailbox-account';
		$t             = get_taxonomy( $taxonomy_name );

		if ( false !== $t ) {
			// Nothing to do.
			return;
		}

		$post_type = $this->settings->get_cpt_underscored_20();

		/** @var WP_Taxonomy|WP_Error $mailboxes_category */
		$mailboxes_category = register_taxonomy(
			'bh-wp-mailbox-account', // Must not exceed 32 characters.
			$post_type,
			array(
				'label'        => __( 'Mailboxes' ),
				'rewrite'      => array( 'slug' => 'mailbox-account' ),
				'hierarchical' => true,
			)
		);
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
	 * Register each mailbox in the mailboxes taxonomy, for searching/filtering.
	 *
	 * @hooked init
	 */
	public function register_mailbox(): void {

		foreach ( $this->settings->get_configured_mailbox_settings() as $email_account ) {

			$account_category_slug = sanitize_title( $email_account->get_account_unique_friendly_name() );

			// false when it does not exist.
			$mailbox_category = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

			if ( false !== $mailbox_category ) {
				// Nothing to do.
				return;
			}

			// Define the category.
			// TODO: Test this does add a new category on each pageload?
			$category_spec_array = array(
				'taxonomy'          => 'bh-wp-mailbox-account',
				'category_nicename' => $this->settings->get_cpt_friendly_name(),
				'cat_name'          => $account_category_slug,
			);

			// Create the category.
			// Call to undefined function wp_insert_category()
			// $new_category_id = \wp_insert_category( $category_spec_array, true );

			// TODO: Check does the term exist.
			if ( ! is_null( term_exists( $email_account->get_account_unique_friendly_name() ) ) ) {
				continue;
			}

			$term = wp_insert_term( $email_account->get_account_unique_friendly_name(), 'bh-wp-mailbox-account', $category_spec_array );

			if ( is_wp_error( $term ) ) {
				$this->logger->error( $term->get_error_message() );
				continue;
			}

			$new_category_id = $term['term_id'];

			if ( is_wp_error( $new_category_id ) ) {
				/** @var WP_Error $new_category_id */
				$this->logger->error( $new_category_id->get_error_message() );
			}
		}
	}
}

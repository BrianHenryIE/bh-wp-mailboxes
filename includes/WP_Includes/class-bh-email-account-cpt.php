<?php
/**
 * A custom post type for saving mailbox settings. Used in emails as post_parent for filtering to mailbox.
 *
 * Use comments to log changes. Use md5 on credentials to watch for changes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Custom post type for email account/mailbox settings storage.
 *
 * @see Email_Account_Settings_Interface
 */
class BH_Email_Account_CPT {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings.
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Registers the email accounts custom post type.
	 *
	 * @hooked init
	 */
	public function register_cpt(): void {

		$post_type = $this->settings->get_email_accounts_cpt_underscored_20();

		$labels = array(
			'name'                     => $this->settings->get_email_accounts_cpt_friendly_name(),
			'singular_name'            => __( 'Email Account', 'bh-wp-mailboxes' ),
			'add_new'                  => __( 'Add New', 'bh-wp-mailboxes' ),
			'add_new_item'             => __( 'Add New Email Account', 'bh-wp-mailboxes' ),
			'edit_item'                => __( 'Email Account', 'bh-wp-mailboxes' ),
			'new_item'                 => __( 'New Email Account', 'bh-wp-mailboxes' ),
			'view_item'                => __( 'View Email Account', 'bh-wp-mailboxes' ),
			'view_items'               => __( 'View Email Accounts', 'bh-wp-mailboxes' ),
			'search_items'             => __( 'Search Email Accounts', 'bh-wp-mailboxes' ),
			'not_found'                => __( 'No email accounts found.', 'bh-wp-mailboxes' ),
			'not_found_in_trash'       => __( 'No email accounts found in Trash.', 'bh-wp-mailboxes' ),
			'parent_item_colon'        => __( 'Parent Email Account:', 'bh-wp-mailboxes' ),
			'all_items'                => __( 'Email Accounts', 'bh-wp-mailboxes' ),
			'archives'                 => __( 'Email Accounts', 'bh-wp-mailboxes' ),
			'attributes'               => __( 'Email Account Attributes', 'bh-wp-mailboxes' ),
			'insert_into_item'         => __( 'Insert into email account', 'bh-wp-mailboxes' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this email account', 'bh-wp-mailboxes' ),
			'filter_items_list'        => __( 'Filter email accounts list', 'bh-wp-mailboxes' ),
			'filter_by_date'           => __( 'Filter by date', 'bh-wp-mailboxes' ),
			'items_list_navigation'    => __( 'Email Accounts list navigation', 'bh-wp-mailboxes' ),
			'items_list'               => __( 'Email Accounts list', 'bh-wp-mailboxes' ),
			'item_published'           => __( 'Email Account published.', 'bh-wp-mailboxes' ),
			'item_published_privately' => __( 'Email Account published privately.', 'bh-wp-mailboxes' ),
			'item_reverted_to_draft'   => __( 'Email Account reverted to draft.', 'bh-wp-mailboxes' ),
			'item_scheduled'           => __( 'Email Account scheduled.', 'bh-wp-mailboxes' ),
			'item_updated'             => __( 'Email Account updated.', 'bh-wp-mailboxes' ),
			'item_link'                => __( 'Email Account Link', 'bh-wp-mailboxes' ),
			'item_link_description'    => __( 'A link to an email account.', 'bh-wp-mailboxes' ),
			'menu_name'                => __( 'Email Accounts', 'bh-wp-mailboxes' ),
			'name_admin_bar'           => __( 'Email Account', 'bh-wp-mailboxes' ),
		);

		$registered_post_type = register_post_type(
			$post_type,
			array(
				'description'         => __( 'Store email account/mailbox configuration in WordPress', 'bh-wp-mailboxes' ),
				'labels'              => $labels,
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => sanitize_title( $this->settings->get_email_accounts_cpt_friendly_name() ) ),
				'supports'            => array( 'title', 'comments' ),
				'public'              => false,
				'show_ui'             => true,
				'menu_position'       => 25,
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
			)
		);

		if ( is_wp_error( $registered_post_type ) ) {
			$this->logger->error( $registered_post_type->get_error_message() );
		}
	}

	/**
	 * Registers post statuses for email accounts.
	 *
	 * - active:   Account is enabled and will be checked during cron runs.
	 * - inactive: Account is configured but disabled; credentials are preserved.
	 *
	 * @hooked init
	 */
	public function register_post_statuses(): void {

		register_post_status(
			'active',
			array(
				'label'                     => _x( 'Active', 'email account status' ),
				'public'                    => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count of email accounts with this status */
				'label_count'               => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>' ),
			)
		);

		register_post_status(
			'inactive',
			array(
				'label'                     => _x( 'Inactive', 'email account status' ),
				'public'                    => false,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: count of email accounts with this status */
				'label_count'               => _n_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>' ),
			)
		);
	}
}

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
			'singular_name'            => 'Email Account',
			'add_new'                  => 'Add New',
			'add_new_item'             => 'Add New Email Account',
			'edit_item'                => 'Email Account',
			'new_item'                 => 'New Email Account',
			'view_item'                => 'View Email Account',
			'view_items'               => 'View Email Accounts',
			'search_items'             => 'Search Email Accounts',
			'not_found'                => 'No email accounts found.',
			'not_found_in_trash'       => 'No email accounts found in Trash.',
			'parent_item_colon'        => 'Parent Email Account:',
			'all_items'                => 'Email Accounts',
			'archives'                 => 'Email Accounts',
			'attributes'               => 'Email Account Attributes',
			'insert_into_item'         => 'Insert into email account',
			'uploaded_to_this_item'    => 'Uploaded to this email account',
			'filter_items_list'        => 'Filter email accounts list',
			'filter_by_date'           => 'Filter by date',
			'items_list_navigation'    => 'Email Accounts list navigation',
			'items_list'               => 'Email Accounts list',
			'item_published'           => 'Email Account published.',
			'item_published_privately' => 'Email Account published privately.',
			'item_reverted_to_draft'   => 'Email Account reverted to draft.',
			'item_scheduled'           => 'Email Account scheduled.',
			'item_updated'             => 'Email Account updated.',
			'item_link'                => 'Email Account Link',
			'item_link_description'    => 'A link to an email account.',
			'menu_name'                => 'Email Accounts',
			'name_admin_bar'           => 'Email Account',
		);

		$registered_post_type = register_post_type(
			$post_type,
			array(
				'description'         => 'Store email account/mailbox configuration in WordPress',
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

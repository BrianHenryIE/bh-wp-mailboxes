<?php
/**
 * Actions and filters for the mailboxes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\Admin\Ajax;
use BrianHenryIE\WP_Mailboxes\Admin\Emails_List_Page;
use BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Repository\Email_WP_Post_Repository;
use Psr\Log\LoggerInterface;

/**
 * Instantiates some classes, then connects them with only `add_action` and `add_filter`.
 */
class BH_WP_Mailboxes_Hooks {

	/**
	 * Email repository shared across hooks that need post persistence.
	 *
	 * @var Email_WP_Post_Repository
	 */
	protected Email_WP_Post_Repository $email_wp_post_repository;

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api      Main API / BH_WP_Mailboxes instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings.
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected LoggerInterface $logger
	) {
		$this->email_wp_post_repository = new Email_WP_Post_Repository( $this->settings->get_cpt_underscored_20(), $this->logger );

		$this->define_cpt_hooks();
		$this->define_cron_hooks();

		$this->define_admin_ui_hooks();
		$this->define_single_email_view_hooks();
		$this->define_ajax_hooks();
	}

	/**
	 * The hooks to register the post type with WordPress.
	 */
	protected function define_cpt_hooks(): void {

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );

		add_action( 'init', $cpt->register_cpt( ... ) );
		add_action( 'init', $cpt->register_post_statuses( ... ) );
		add_action( 'init', $cpt->register_mailboxes_taxonomy( ... ) );

		add_action( 'init', $cpt->register_mailbox( ... ) );
	}

	/**
	 * Cron will regularly check for emails, and maybe delete locally saved emails.
	 */
	protected function define_cron_hooks(): void {

		$cron = new Cron( $this->api, $this->settings, $this->logger );

		add_action( 'plugins_loaded', $cron->add_cron_jobs( ... ), 20 );

		// {cpt_name}_fetch_emails_job.
		add_action( $cron->get_fetch_emails_cron_hook_name(), $cron->background_fetch_emails( ... ) );

		// {cpt_name}_delete_local_emails_job.
		add_action( $cron->get_delete_local_emails_cron_hook_name(), $cron->background_delete_local_emails( ... ) );
	}

	/**
	 * Define hooks related to the list table view.
	 */
	protected function define_admin_ui_hooks(): void {

		$mailbox_list_page = new Emails_List_Page( $this->email_wp_post_repository, $this->api, $this->settings, $this->logger );

		$post_type = str_replace( '-', '_', sanitize_title( $this->settings->get_cpt_friendly_name() ) );

		add_action( 'manage_posts_extra_tablenav', $mailbox_list_page->print_extra_table_controls_at_top( ... ) );

		add_filter( "manage_{$post_type}_posts_columns", $mailbox_list_page->table_head( ... ) );
		add_action( "manage_{$post_type}_posts_custom_column", $mailbox_list_page->table_content( ... ), 10, 2 );
		add_action( 'restrict_manage_posts', $mailbox_list_page->table_filters( ... ) );

		add_action( 'admin_enqueue_scripts', $mailbox_list_page->enqueue_styles( ... ) );
		add_action( 'admin_enqueue_scripts', $mailbox_list_page->enqueue_scripts( ... ) );
	}

	/**
	 * Hooks for the single email edit/view screen.
	 */
	protected function define_single_email_view_hooks(): void {

		$view      = new Single_Email_View( $this->settings, $this->api, $this->email_wp_post_repository, $this->logger );
		$post_type = $this->settings->get_cpt_underscored_20();

		add_action( "add_meta_boxes_{$post_type}", $view->add_meta_boxes( ... ) );
		add_action( 'admin_enqueue_scripts', $view->enqueue_scripts( ... ) );
		add_filter( 'wp_insert_post_data', $view->prevent_content_edits( ... ), 10, 2 );
		add_action( 'post_updated', $view->log_status_change( ... ), 10, 3 );

		add_action( 'wp_ajax_bh_wp_mailboxes_mark_read', $view->ajax_mark_read( ... ) );
		add_action( 'wp_ajax_bh_wp_mailboxes_mark_unread', $view->ajax_mark_unread( ... ) );
		add_action( 'wp_ajax_bh_wp_mailboxes_delete_on_server', $view->ajax_delete_on_server( ... ) );
	}

	/**
	 * Hooks for handling the JavaScript functions.
	 * i.e. the "check for emails now" button! on the list table view, but which could be placed
	 * on any view, e.g. settings page.
	 */
	protected function define_ajax_hooks(): void {

		$ajax = new Ajax( $this->api, $this->settings, $this->logger );

		add_action( 'wp_ajax_bh_wp_mailboxes_check_email', $ajax->check_email( ... ) );
	}
}

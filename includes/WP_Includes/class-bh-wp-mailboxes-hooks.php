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
use BrianHenryIE\WP_Mailboxes\Admin\Single_Email_View_Ajax;
use BrianHenryIE\WP_Mailboxes\Admin\Status_View;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
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
	 * Factory for creating BH_Email instances from posts.
	 *
	 * @var BH_Email_Factory
	 */
	protected BH_Email_Factory $bh_email_factory;

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
		$this->bh_email_factory         = new BH_Email_Factory( $this->logger );
		$this->email_wp_post_repository = new Email_WP_Post_Repository( $this->settings->get_emails_cpt_underscored_20(), $this->bh_email_factory, $this->logger );

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

		$account_cpt = new BH_Email_CPT( $this->settings, $this->logger );

		add_action( 'init', $account_cpt->register_cpt( ... ) );
		add_action( 'init', $account_cpt->register_post_statuses( ... ) );

		$email_cpt = new BH_Email_CPT( $this->settings, $this->logger );

		add_action( 'init', $email_cpt->register_cpt( ... ) );
		add_action( 'init', $email_cpt->register_post_statuses( ... ) );

		add_filter( 'wp_insert_post_data', $email_cpt->prevent_content_edits( ... ), 10, 2 );
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

		$status_view = new Status_View( $this->api, $this->settings, $this->email_wp_post_repository, $this->logger );
		add_action( 'admin_notices', $status_view->display( ... ) );

		$mailbox_list_page = new Emails_List_Page( $this->email_wp_post_repository, $this->api, $this->settings, $this->logger );

		$post_type = str_replace( '-', '_', sanitize_title( $this->settings->get_emails_cpt_friendly_name() ) );

		add_action( 'manage_posts_extra_tablenav', $mailbox_list_page->print_extra_table_controls_at_top( ... ) );

		add_filter( "manage_{$post_type}_posts_columns", $mailbox_list_page->table_head( ... ) );
		add_action( "manage_{$post_type}_posts_custom_column", $mailbox_list_page->table_content( ... ), 10, 2 );
		add_action( 'restrict_manage_posts', $mailbox_list_page->table_filters( ... ) );

		add_action( 'admin_enqueue_scripts', $mailbox_list_page->enqueue_styles( ... ) );
		add_action( 'admin_enqueue_scripts', $mailbox_list_page->enqueue_scripts( ... ) );
		add_action( 'pre_get_posts', $mailbox_list_page->show_all_post_statuses( ... ) );
	}

	/**
	 * Hooks for the single email edit/view screen.
	 */
	protected function define_single_email_view_hooks(): void {

		$view      = new Single_Email_View( $this->settings, $this->api, $this->email_wp_post_repository, $this->logger );
		$post_type = $this->settings->get_emails_cpt_underscored_20();

		add_action( "add_meta_boxes_{$post_type}", $view->add_meta_boxes( ... ) );
		add_action( 'admin_enqueue_scripts', $view->enqueue_scripts( ... ) );

		$ajax = new Single_Email_View_Ajax( $this->settings, $this->api, $this->email_wp_post_repository, $this->logger );

		add_action( 'wp_ajax_bh_wp_mailboxes_mark_read', $ajax->ajax_mark_read( ... ) );
		add_action( 'wp_ajax_bh_wp_mailboxes_mark_unread', $ajax->ajax_mark_unread( ... ) );
		add_action( 'wp_ajax_bh_wp_mailboxes_delete_on_server', $ajax->ajax_delete_on_server( ... ) );
	}

	/**
	 * Hooks for handling the JavaScript functions.
	 * i.e. the "check for emails now" button! on the list table view, but which could be placed
	 * on any view, e.g. settings page.
	 */
	protected function define_ajax_hooks(): void {

		$ajax = new Ajax( $this->api, $this->settings, $this->logger );

		add_action( 'wp_ajax_bh_wp_mailboxes_check_email', $ajax->check_email( ... ) );
		add_action( 'wp_ajax_bh_wp_mailboxes_check_account', $ajax->check_account( ... ) );
		add_action( 'wp_ajax_bh_wp_mailboxes_set_fetch_since', $ajax->check_account( ... ) );
	}
}

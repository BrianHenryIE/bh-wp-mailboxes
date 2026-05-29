<?php
/**
 * Actions and filters for the mailboxes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\Admin\Ajax;
use BrianHenryIE\WP_Mailboxes\Admin\Mailbox_List_Page;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerInterface;

/**
 * Instantiates some classes, then connects them with only `add_action` and `add_filter`.
 */
class BH_WP_Mailboxes_Hooks {

	/**
	 * No logging is done in this class, this is purely for instantiating other classes.
	 */
	protected LoggerInterface $logger;

	/**
	 * The settings provided by the plugin defining the mailboxes and the behaviour (e.g. delete schedule).
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * Instance of the main bh-wp-mailboxes utility class.
	 * BH_WP_Mailboxes extends API (i.e. implements API_Interface).
	 *
	 * @var API_Interface
	 */
	protected API_Interface $api;

	public function __construct( API_Interface $api, BH_WP_Mailboxes_Settings_Interface $settings, LoggerInterface $logger ) {

		$this->logger   = $logger;
		$this->settings = $settings;
		$this->api      = $api;

		$this->define_cpt_hooks();
		$this->define_cron_hooks();

		$this->define_admin_ui_hooks();
		$this->define_ajax_hooks();
	}

	/**
	 * The hooks to register the post type with WordPress.
	 */
	protected function define_cpt_hooks(): void {

		$cpt = new BH_Email_CPT( $this->settings, $this->logger );

		add_action( 'init', array( $cpt, 'register_cpt' ) );
		add_action( 'init', array( $cpt, 'register_mailboxes_taxonomy' ) );

		add_action( 'init', array( $cpt, 'register_mailbox' ) );
	}

	/**
	 * Cron will regularly check for emails, and maybe delete locally saved emails.
	 */
	protected function define_cron_hooks(): void {

		$cron = new Cron( $this->api, $this->settings, $this->logger );

		add_action( 'plugins_loaded', array( $cron, 'add_cron_jobs' ), 20 );

		// {cpt_name}_fetch_emails_job.
		add_action( $cron->get_fetch_emails_cron_hook_name(), array( $cron, 'background_fetch_emails' ) );

		// {cpt_name}_delete_local_emails_job.
		add_action( $cron->get_delete_local_emails_cron_hook_name(), array( $cron, 'background_delete_local_emails' ) );
	}

	/**
	 * Define hooks related to the list table view.
	 */
	protected function define_admin_ui_hooks(): void {

		$mailbox_list_page = new Mailbox_List_Page( $this->api, $this->settings, $this->logger );

		$post_type = str_replace( '-', '_', sanitize_title( $this->settings->get_cpt_friendly_name() ) );

		add_action( 'manage_posts_extra_tablenav', array( $mailbox_list_page, 'print_extra_table_controls_at_top' ) );

		add_filter( "manage_{$post_type}_posts_columns", array( $mailbox_list_page, 'table_head' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( $mailbox_list_page, 'table_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $mailbox_list_page, 'table_filters' ) );

		add_action( 'admin_enqueue_scripts', array( $mailbox_list_page, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $mailbox_list_page, 'enqueue_scripts' ) );
	}

	/**
	 * Hooks for handling the JavaScript functions.
	 * i.e. the "check for emails now" button! on the list table view, but which could be placed
	 * on any view, e.g. settings page.
	 */
	protected function define_ajax_hooks(): void {

		$ajax = new Ajax( $this->api, $this->settings, $this->logger );

		add_action( 'wp_ajax_bh_wp_mailboxes_check_email', array( $ajax, 'check_email' ) );
	}
}

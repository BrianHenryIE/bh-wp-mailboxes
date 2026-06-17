<?php
/**
 * The full page that displays the list of emails, with UI controls.
 *
 * Implements various `WP_List_Table` hooks to customise the view.
 *
 * @see WP_List_Table
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Renders the emails list table page with custom columns and controls.
 */
class Emails_List_Page {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param Email_WP_Post_Repository           $email_wp_post_repository Repository for email CPT posts.
	 * @param API_Interface                      $api                      Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                 Plugin settings.
	 * @param LoggerInterface                    $logger                   PSR-3 logger.
	 */
	public function __construct(
		protected Email_WP_Post_Repository $email_wp_post_repository,
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Prints extra table nav controls at the top of the list table.
	 *
	 * @hooked manage_posts_extra_tablenav
	 * @param string $which The location of the extra table nav markup: 'top' or 'bottom'.
	 */
	public function print_extra_table_controls_at_top( string $which ): void {

		// Only add to our cpt edit screen.
		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();
		if ( null === $screen || $screen->post_type !== $post_type ) {
			return;
		}

		if ( 'top' !== $which ) {
			return;
		}

		// "Check all" when the mailbox has more than one account, otherwise "Check now".
		$label = count( $this->api->get_email_accounts() ) > 1
			? __( 'Check all', 'bh-wp-mailboxes' )
			: __( 'Check now', 'bh-wp-mailboxes' );

		wp_nonce_field( 'bh-wp-mailboxes-check-email', '_wpnonce_checknow' );

		// Rendered hidden here; the JS moves it into the page title, replacing the "Add New" button.
		printf(
			'<button type="button" id="check-email" class="page-title-action" style="display:none;">%s</button>',
			esc_html( $label )
		);
	}

	/**
	 * Customises the column headers for the emails list table.
	 *
	 * @hooked manage_{$post_type}_posts_columns
	 *
	 * @param array<string, string> $defaults Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function table_head( array $defaults ): array {

		$columns = array();

		$columns['cb'] = $defaults['cb']; // Check-box.

		$columns['title'] = __( 'Subject', 'bh-wp-mailboxes' );
		$columns['from']  = __( 'From', 'bh-wp-mailboxes' );
		$columns['date']  = __( 'Date', 'bh-wp-mailboxes' );

		return $columns;
	}

	/**
	 * Outputs cell content for custom columns in the emails list table.
	 *
	 * @hooked manage_{$post_type}_posts_custom_column
	 *
	 * @param string $column_name The name of the current column.
	 * @param int    $post_id     The current post ID.
	 *
	 * @return void
	 */
	public function table_content( $column_name, $post_id ): void {

		$email = $this->email_wp_post_repository->find_by_post_id( $post_id );

		switch ( $column_name ) {
			case 'from':
				echo esc_html( $email->get_from_email() );
				break;
			default:
				break;
		}
	}

	/**
	 * Outputs filter controls above the emails list table.
	 *
	 * @hooked restrict_manage_posts
	 *
	 * @see \WP_Posts_List_Table::categories_dropdown()
	 */
	public function table_filters(): void {
		global $wpdb;
		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();
		if ( null === $screen || $screen->post_type !== $post_type ) {
			return;
		}

		// TODO: Add account filter when multiple accounts exist.
	}

	/**
	 * Show all post statuses on the emails list table.
	 *
	 * @hooked pre_get_posts
	 *
	 * @param \WP_Query $query The current WP_Query instance.
	 */
	public function show_all_post_statuses( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== $this->settings->get_emails_cpt_underscored_20() ) {
			return;
		}
		$query->set( 'post_status', 'any' );
	}

	/**
	 * Register the stylesheets for the logs page.
	 *
	 * @hooked admin_enqueue_scripts
	 */
	public function enqueue_styles(): void {

		$current_screen = get_current_screen();

		if ( is_null( $current_screen ) ) {
			return;
		}

		if ( $this->settings->get_emails_cpt_underscored_20() !== $current_screen->post_type ) {
			return;
		}

		$handle = "{$this->settings->get_emails_cpt_dashed()}-list-css";

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $handle !== $_GET['page'] ) {
			return;
		}

		// TODO: Enqueue stylesheet when one is added.
	}


	/**
	 * Script to handle AJAX check-mail.
	 *
	 * @hooked admin_enqueue_scripts
	 */
	public function enqueue_scripts(): void {

		// Only enqueue on the correct post type list page.

		$current_screen = get_current_screen();

		if ( is_null( $current_screen ) ) {
			return;
		}

		if ( $this->settings->get_emails_cpt_underscored_20() !== $current_screen->post_type ) {
			return;
		}

		$handle = "{$this->settings->get_emails_cpt_dashed()}-list-page-script";

		$js_file = plugin_dir_url( __FILE__ ) . 'js/bh-wp-mailboxes.js';
		$version = BH_WP_Mailboxes::get_version();

		wp_enqueue_script( $handle, $js_file, array( 'jquery' ), $version, true );

		// The AJAX actions are scoped to this instance's post types (see BH_WP_Mailboxes_Hooks::define_ajax_hooks()),
		// so the JS must post the matching, suffixed action names.
		wp_localize_script(
			$handle,
			'bh_wp_mailboxes_ajax',
			array(
				'check_email_action'   => 'bh_wp_mailboxes_check_email_' . $this->settings->get_emails_cpt_underscored_20(),
				'check_account_action' => 'bh_wp_mailboxes_check_account_' . $this->settings->get_email_accounts_cpt_underscored_20(),
			)
		);
	}
}

<?php
/**
 * The full page that displays the list of emails, with UI controls.
 *
 * Implements various `WP_List_Table` hooks to customise the view.
 *
 * @see WP_List_Table
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Repository\Email_WP_Post_Repository;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Emails_List_Page {

	use LoggerAwareTrait;

	public function __construct(
		protected Email_WP_Post_Repository $email_wp_post_repository,
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	// Add New button is based on
	// @var WP_Post_Type $a

	/** @var \WP_Post_Type */
	protected $a;
	// if ( current_user_can( $post_type_object->cap->create_posts ) ) {


	/**
	 * @hooked manage_posts_extra_tablenav
	 * @param string $which The location of the extra table nav markup: 'top' or 'bottom'.
	 */
	public function print_extra_table_controls_at_top( string $which ): void {

		// Only add to our cpt edit screen.
		$screen    = get_current_screen();
		$post_type = $this->settings->get_cpt_underscored_20();
		if ( $screen->post_type !== $post_type ) {
			return;
		}

		if ( 'top' !== $which ) {
			return;
		}
		wp_nonce_field( 'bh-wp-mailboxes-check-email', '_wpnonce_checknow' );
		echo '<button name="check-email" id="check-email" class="button button-primary">Check now</button>';
	}


	/**
	 * @hooked manage_{$post_type}_posts_columns
	 *
	 * @param array<string, string> $defaults
	 *
	 * @return array<string, string>
	 */
	public function table_head( array $defaults ): array {

		$columns = array();

		$columns['cb'] = $defaults['cb'];

		$columns['title'] = __( 'Subject', 'bh-wp-mailboxes' );
		$columns['from']  = __( 'From', 'bh-wp-mailboxes' );
		$columns['date']  = __( 'Date', 'bh-wp-mailboxes' );

		return $columns;
	}

	/**
	 * @hooked manage_{$post_type}_posts_custom_column
	 *
	 * @param string $column_name
	 * @param int    $post_id
	 *
	 * @return void
	 */
	public function table_content( $column_name, $post_id ): void {

		$post = get_post( $post_id );

		// check post-type.
		$email = $this->email_wp_post_repository->find_by_post_id( $post_id );

		switch ( $column_name ) {
			case 'from':
				echo $email->get_from_email();
				break;
			default:
				break;
		}
	}

	/**
	 * @hooked restrict_manage_posts
	 *
	 * @see \WP_Posts_List_Table::categories_dropdown()
	 */
	public function table_filters(): void {
		global $wpdb;
		$screen    = get_current_screen();
		$post_type = $this->settings->get_cpt_underscored_20();
		if ( $screen->post_type !== $post_type ) {
			return;
		}

		// If count ( accounts ) > 1

		// echo 'account filters';

		// $dates = $wpdb->get_results( "SELECT EXTRACT(YEAR FROM meta_value) as year,  EXTRACT( MONTH FROM meta_value ) as month FROM $wpdb->postmeta WHERE meta_key = '_bs_meta_event_date' AND post_id IN ( SELECT ID FROM $wpdb->posts WHERE post_type = 'event' AND post_status != 'trash' ) GROUP BY year, month " ) ;
		//
		// echo '';
		// echo '' . __( 'Show all event dates', 'textdomain' ) . '';
		//
		// foreach( $dates as $date ) {
		// $month = ( strlen( $date->month ) == 1 ) ? 0 . $date->month : $date->month;
		// $value = $date->year . '-' . $month . '-' . '01 00:00:00';
		// $name = date( 'F Y', strtotime( $value ) );
		//
		// $selected = ( !empty( $_GET['event_date'] ) AND $_GET['event_date'] == $value ) ? 'selected="select"' : '';
		// echo '' . $name . '';
		// }
		// echo '';
		//
		// $ticket_statuses = get_ticket_statuses();
		// echo '';
		// echo '' . __( 'Show all ticket statuses', 'textdomain' ) . '';
		// foreach( $ticket_statuses as $value => $name ) {
		// $selected = ( !empty( $_GET['ticket_status'] ) AND $_GET['ticket_status'] == $value ) ? 'selected="selected"' : '';
		// echo '' . $name . '';
		// }
		// echo '';
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

		if ( $this->settings->get_cpt_underscored_20() !== $current_screen->post_type ) {
			return;
		}

		$handle = "{$this->settings->get_cpt_dashed()}-list-css";

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $handle !== $_GET['page'] ) {
			return;
		}

		// $css_file = plugin_dir_url( __FILE__ ) . '/css/bh-wp-logger.css';
		// $version  = '1.0.0';
		//
		// wp_enqueue_style( $handle, $css_file, array(), $version, 'all' );
	}


	/**
	 * Script to handle AJAX check-mail.
	 *
	 * @hooked admin_enqueue_scripts
	 */
	public function enqueue_scripts(): void {

		// edit.php?post_status=all&post_type=bh_wp_mailboxes_cpt&paged=1

		$current_screen = get_current_screen();

		if ( is_null( $current_screen ) ) {
			return;
		}

		if ( $this->settings->get_cpt_underscored_20() !== $current_screen->post_type ) {
			return;
		}

		$handle = "{$this->settings->get_cpt_dashed()}-list-script";

		$js_file = plugin_dir_url( __FILE__ ) . 'js/bh-wp-mailboxes.js';
		$version = '1.0.0';

		wp_enqueue_script( $handle, $js_file, array( 'jquery' ), $version, true );
	}
}

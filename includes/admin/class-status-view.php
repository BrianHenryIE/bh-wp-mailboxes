<?php
/**
 * Status table rendered at the top of the emails list view.
 *
 * Shows per-account: last fetched time, last failure time, and email count.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTimeInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Renders a per-account status summary above the emails list table.
 */
class Status_View {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api                     Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                Plugin settings.
	 * @param Email_WP_Post_Repository           $email_wp_post_repository Email repository (for counts).
	 * @param LoggerInterface                    $logger                  PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Email_WP_Post_Repository $email_wp_post_repository,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Renders the status table at the top of the emails list tablenav.
	 *
	 * @hooked manage_posts_extra_tablenav
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	public function display( string $which ): void {

		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();

		if ( null === $screen || $screen->post_type !== $post_type ) {
			return;
		}
		if ( 'top' !== $which ) {
			return;
		}

		$accounts = $this->api->get_email_accounts();

		echo '<div id="bh-mailboxes-status" class="bh-mailboxes-status">';

		if ( empty( $accounts ) ) {
			echo '<p>' . esc_html__( 'No accounts configured.', 'bh-wp-mailboxes' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach ( array( 'Account', 'Status', 'Emails', 'Last Fetched', 'Last Failure' ) as $label ) {
			echo '<th>' . esc_html__( $label, 'bh-wp-mailboxes' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $accounts as $account ) {
			$email_count  = $this->email_wp_post_repository->count_for_account_email( $account->email_address );
			$last_fetched = $account->last_successful_login_time;
			$last_failure = $account->last_failed_login_time;

			echo '<tr>';
			echo '<td>' . esc_html( $account->email_address ) . '</td>';
			echo '<td>' . esc_html( $account->is_active() ? __( 'Active', 'bh-wp-mailboxes' ) : __( 'Inactive', 'bh-wp-mailboxes' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $email_count ) . '</td>';
			echo '<td>' . esc_html( $this->format_time( $last_fetched ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_time( $last_failure ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Formats a datetime as a human-readable "X ago" string, or "Never" if null.
	 *
	 * @param ?DateTimeInterface $time The datetime to format.
	 */
	protected function format_time( ?DateTimeInterface $time ): string {
		if ( null === $time ) {
			return __( 'Never', 'bh-wp-mailboxes' );
		}
		/* translators: %s: human-readable time difference, e.g. "5 minutes" */
		return sprintf( __( '%s ago', 'bh-wp-mailboxes' ), human_time_diff( $time->getTimestamp() ) );
	}
}

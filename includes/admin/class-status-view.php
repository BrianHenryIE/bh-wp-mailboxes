<?php
/**
 * Status cards rendered at the top of the emails list view.
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
	 * Renders the status cards at the top of the emails list tablenav.
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

		echo '<style>
			#bh-mailboxes-status { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 12px; }
			.bh-mailboxes-account-card { background:#fff; border:1px solid #c3c4c7; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:8px 12px 10px; min-width:170px; }
			.bh-mailboxes-account-card__title { font-weight:600; font-size:13px; padding-bottom:5px; margin-bottom:5px; border-bottom:1px solid #f0f0f1; }
			.bh-mailboxes-account-card__details { margin:0; display:grid; grid-template-columns:auto 1fr; gap:2px 10px; font-size:12px; }
			.bh-mailboxes-account-card__details dt { color:#646970; font-weight:500; }
			.bh-mailboxes-account-card__details dd { margin:0; }
		</style>';
		echo '<div id="bh-mailboxes-status" class="bh-mailboxes-status">';

		if ( empty( $accounts ) ) {
			echo '<p>' . esc_html__( 'No accounts configured.', 'bh-wp-mailboxes' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( $accounts as $account ) {
			$email_count  = $this->email_wp_post_repository->count_for_account_email( $account );
			$status_label = $account->is_active() ? __( 'Active', 'bh-wp-mailboxes' ) : __( 'Inactive', 'bh-wp-mailboxes' );

			echo '<div class="bh-mailboxes-account-card">';
			echo '<div class="bh-mailboxes-account-card__title">' . esc_html( $account->email_address ) . '</div>';
			echo '<dl class="bh-mailboxes-account-card__details">';
			echo '<dt>' . esc_html__( 'Status', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd>' . esc_html( $status_label ) . '</dd>';
			echo '<dt>' . esc_html__( 'Emails', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd>' . esc_html( (string) $email_count ) . '</dd>';
			echo '<dt>' . esc_html__( 'Last fetched', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd>' . esc_html( $this->format_time( $account->last_successful_login_time ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Last failure', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd>' . esc_html( $this->format_time( $account->last_failed_login_time ) ) . '</dd>';
			echo '</dl>';
			echo '</div>';
		}

		echo '</div>';
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

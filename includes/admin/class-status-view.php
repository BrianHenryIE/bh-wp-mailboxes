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
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Repository_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateInterval;
use DateTimeImmutable;
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
	 * @param Email_Repository_Interface         $email_wp_post_repository Email repository (for counts).
	 * @param LoggerInterface                    $logger                  PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Email_Repository_Interface $email_wp_post_repository,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Renders the status cards in the admin notices area of the emails list screen.
	 *
	 * @hooked admin_notices
	 */
	public function display(): void {

		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();

		if ( null === $screen || $screen->post_type !== $post_type || 'edit' !== $screen->base ) {
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
			.bh-mailboxes-account-card__actions { margin-top:8px; display:flex; align-items:center; gap:5px; }
			.bh-fetch-since-input { width:120px; }
			.bh-fetch-since-toggle { background:none; border:none; box-shadow:none; cursor:pointer; padding:2px; color:#787c82; vertical-align:middle; line-height:1; min-height:0; }
			.bh-fetch-since-toggle:hover { color:#1d2327; background:none; border:none; box-shadow:none; }
			.bh-fetch-since-toggle .dashicons { font-size:18px; width:18px; height:18px; pointer-events:none; }
			.bh-check-notice { transition:border-left-color 0.3s ease; }
			.bh-check-notice .spinner { float:none; margin:0 5px 0 0; vertical-align:middle; }
		</style>';
		echo '<div id="bh-mailboxes-status" class="bh-mailboxes-status">';

		if ( empty( $accounts ) ) {
			echo '<p>' . esc_html__( 'No accounts configured.', 'bh-wp-mailboxes' ) . '</p>';
			echo '</div>';
			return;
		}

		wp_nonce_field( 'bh-wp-mailboxes-account-actions', '_wpnonce_account_actions' );

		foreach ( $accounts as $account ) {
			$email_count  = $this->email_wp_post_repository->count_for_account_email( $account );
			$status_label = $account->is_active() ? __( 'Active', 'bh-wp-mailboxes' ) : __( 'Inactive', 'bh-wp-mailboxes' );
			$since_value  = ( $account->last_successful_login_time ?? new DateTimeImmutable()->sub( new DateInterval( 'P1W' ) ) )->format( 'Y-m-d' );
			$account_id   = (string) $account->get_post_id();

			echo '<div class="bh-mailboxes-account-card" data-account-id="' . esc_attr( $account_id ) . '" data-account-name="' . esc_attr( $account->display_name ) . '">';
			echo '<div class="bh-mailboxes-account-card__title">' . esc_html( $account->email_address ) . '</div>';
			echo '<dl class="bh-mailboxes-account-card__details">';
			echo '<dt>' . esc_html__( 'Status', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd>' . esc_html( $status_label ) . '</dd>';
			echo '<dt>' . esc_html__( 'Emails', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd data-field="email-count">' . esc_html( (string) $email_count ) . '</dd>';
			echo '<dt>' . esc_html__( 'Last fetched', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd data-field="last-fetched">' . esc_html( $this->format_time( $account->last_successful_login_time ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Last failure', 'bh-wp-mailboxes' ) . '</dt>';
			echo '<dd data-field="last-failure">' . esc_html( $this->format_time( $account->last_failed_login_time ) ) . '</dd>';
			echo '</dl>';
			echo '<div class="bh-mailboxes-account-card__actions">';
			echo '<button type="button" class="button button-primary button-small bh-check-account" data-account-id="' . esc_attr( $account_id ) . '">' . esc_html__( 'Check now', 'bh-wp-mailboxes' ) . '</button>';
			echo '<button type="button" class="bh-fetch-since-toggle" data-account-id="' . esc_attr( $account_id ) . '" title="' . esc_attr__( 'Set the date from which emails will be fetched', 'bh-wp-mailboxes' ) . '"><span class="dashicons dashicons-clock" aria-hidden="true"></span></button>';
			echo '</div>';
			echo '<input type="date" class="bh-fetch-since-input" data-account-id="' . esc_attr( $account_id ) . '" value="' . esc_attr( $since_value ) . '" style="display:none;margin-top:6px;width:100%;">';
			echo '</div>';
		}

		echo '</div>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelector(".wp-header-end").after(document.getElementById("bh-mailboxes-status"));});</script>';
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

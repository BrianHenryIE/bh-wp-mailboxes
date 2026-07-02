<?php
/**
 * Handle buttons on the list page. (and maybe settings page).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Handles AJAX requests from the admin UI.
 */
class Emails_List_Table_Ajax {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api      Main API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings.
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Triggers an immediate email check for the current mailbox.
	 *
	 * The action is suffixed with the emails CPT so each library instance only handles its own request.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_check_email_{emails_cpt}
	 */
	public function check_email(): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['mailboxes_cpt'] )
			|| ! is_string( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-check-email' )
			|| ! is_string( $_POST['mailboxes_cpt'] )
		) {
			return;
		}

		// bh-wp-mailboxes could be hooked for many plugins.
		if ( $this->settings->get_emails_cpt_underscored_20() !== sanitize_key( $_POST['mailboxes_cpt'] ) ) {
			return;
		}

		$result = $this->api->check_email();

		if ( $result->success ) {
			wp_send_json_success( (array) $result );
		} else {
			wp_send_json_error( (array) $result );
		}
	}

	/**
	 * Triggers an immediate email check for a single account.
	 *
	 * The action is suffixed with the accounts CPT so each library instance only handles its own request.
	 *
	 * @hooked wp_ajax_bh_wp_mailboxes_check_account_{accounts_cpt}
	 * @hooked wp_ajax_bh_wp_mailboxes_set_fetch_since_{accounts_cpt}
	 */
	public function check_account(): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['account_post_id'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-account-actions' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
		}

		$account_post_id = (int) $_POST['account_post_id'];
		$account         = null;
		$all_accounts    = $this->api->get_email_accounts();
		foreach ( $all_accounts as $candidate ) {
			if ( $candidate->get_post_id() === $account_post_id ) {
				$account = $candidate;
				break;
			}
		}

		if ( null === $account ) {
			wp_send_json_error( array( 'message' => 'Account not found.' ) );
		}

		$since = null;
		if ( isset( $_POST['since_date'] ) ) {
			if ( ! is_string( $_POST['since_date'] ) ) {
				wp_send_json_error( array( 'message' => 'Invalid value for since.' ), 400 );
			}
			$since_raw = sanitize_text_field( wp_unslash( $_POST['since_date'] ) );
			$since     = DateTimeImmutable::createFromFormat( 'Y-m-d', $since_raw, new DateTimeZone( 'UTC' ) );
			if ( false === $since ) {
				wp_send_json_error( array( 'message' => 'Unable to parse value for since.' ), 400 );
			}
		}

		$result = $this->api->check_email_for_account( $account, $since );
		wp_send_json_success(
			array(
				'new_email_count' => count( $result->bh_emails ),
				// Post IDs of the new emails, so the JS can highlight their rows in the list table.
				'new_email_ids'   => array_map( fn( $email ) => $email->get_post_id(), $result->bh_emails ),
				/* translators: shown in the status card immediately after a manual check */
				'last_fetched'    => __( 'Just now', 'bh-wp-mailboxes' ),
			)
		);
	}
}

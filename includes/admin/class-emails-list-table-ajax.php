<?php
/**
 * Handles the mailbox-wide "Check all" button on the list page. Per-account actions live in Email_Accounts_Ajax.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
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

		// sanitize_key() only keeps [a-z0-9_-], so the values need no unslashing.
		$nonce         = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$mailboxes_cpt = isset( $_POST['mailboxes_cpt'] ) && is_string( $_POST['mailboxes_cpt'] ) ? sanitize_key( $_POST['mailboxes_cpt'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( '' === $nonce || '' === $mailboxes_cpt || ! wp_verify_nonce( $nonce, 'bh-wp-mailboxes-check-email' ) ) {
			return;
		}

		// bh-wp-mailboxes could be hooked for many plugins.
		if ( $this->settings->get_emails_cpt_underscored_20() !== $mailboxes_cpt ) {
			return;
		}

		$result = $this->api->check_email();

		$payload = array(
			'new_email_count' => count( $result->get_emails() ),
			// Post IDs of the new emails, so the JS can highlight their rows in the list table.
			'new_email_ids'   => array_map( fn( New_Email_Interface $email ): int => $email->get_email()->get_post_id(), $result->get_emails() ),
			'accounts'        => array_map(
				fn( Check_Email_Account_Result $account_result ): array => array(
					'account_post_id' => $account_result->bh_account->get_post_id(),
					'name'            => $account_result->bh_account->display_name,
					'email_address'   => $account_result->bh_account->email_address,
					'status'          => $account_result->success ? 'success' : ( $account_result->skipped ? 'skipped' : 'failed' ),
					'message'         => $account_result->message,
					'new_email_count' => count( $account_result->new_emails ),
					'warnings'        => $account_result->warnings,
				),
				$result->account_results
			),
		);

		if ( $result->success ) {
			wp_send_json_success( $payload );
		} else {
			$failures           = $result->get_failures();
			$payload['message'] = sprintf(
				/* translators: 1: number of accounts that failed, 2: total number of accounts checked */
				_n( '%1$d of %2$d accounts could not be checked.', '%1$d of %2$d accounts could not be checked.', count( $failures ), 'bh-wp-mailboxes' ),
				count( $failures ),
				count( $result->account_results )
			);
			wp_send_json_error( $payload );
		}
	}
}

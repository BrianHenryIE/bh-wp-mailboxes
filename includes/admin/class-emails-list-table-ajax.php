<?php
/**
 * Handles the mailbox-wide "Check all" button on the list page. Per-account actions live in Email_Accounts_Ajax.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
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
}

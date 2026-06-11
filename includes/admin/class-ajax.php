<?php
/**
 * Handle buttons on the list page. (and maybe settings page).
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
class Ajax {

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
	 * @hooked wp_ajax_bh_wp_mailboxes_check_email
	 */
	public function check_email(): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['mailboxes_cpt'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-check-email' ) ) {
			return;
		}

		// bh-wp-mailboxes could be hooked for many plugins.
		if ( $this->settings->get_emails_cpt_underscored_20() !== sanitize_key( $_POST['mailboxes_cpt'] ) ) {
			return;
		}

		$result = $this->api->check_email();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}

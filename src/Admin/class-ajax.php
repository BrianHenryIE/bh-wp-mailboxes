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

class Ajax {

	use LoggerAwareTrait;

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	protected API_Interface $api;

	public function __construct( API_Interface $api, BH_WP_Mailboxes_Settings_Interface $settings, LoggerInterface $logger ) {

		$this->setLogger( $logger );
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * @hooked wp_ajax_bh_wp_mailboxes_check_email
	 */
	public function check_email(): void {

		if ( ! isset( $_POST['_wpnonce'], $_POST['mailboxes_cpt'] )
			 || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bh-wp-mailboxes-check-email' ) ) {
			return;
		}

		// bh-wp-mailboxes could be hooked for many plugins.
		if ( $this->settings->get_cpt_underscored_20() !== sanitize_key( $_POST['mailboxes_cpt'] ) ) {
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

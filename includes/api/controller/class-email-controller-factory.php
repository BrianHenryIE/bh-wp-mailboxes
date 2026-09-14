<?php
/**
 * Builds the controller for a stored email: remote-capable when the account's connection supports fetching.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Controller;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

/**
 * `Supports_Fetching` ? {@see Remote_Email_Controller} : {@see Local_Email_Controller}.
 */
class Email_Controller_Factory {

	/**
	 * Wrap an email; remote-capable when the account's connection supports fetching.
	 *
	 * @param API_Interface    $api     The main API instance.
	 * @param BH_Email_Account $account The account the email belongs to; its connection determines local vs remote.
	 * @param BH_Email         $email   The email.
	 */
	public function make( API_Interface $api, BH_Email_Account $account, BH_Email $email ): Email_Controller_Interface {
		$connection_class = $account->connection_type_class;
		$interfaces       = class_exists( $connection_class ) ? class_implements( $connection_class ) : array();

		if ( is_array( $interfaces ) && in_array( Supports_Fetching::class, $interfaces, true ) ) {
			return new Remote_Email_Controller( email: $email, api: $api );
		}

		return new Local_Email_Controller( email: $email, api: $api );
	}
}

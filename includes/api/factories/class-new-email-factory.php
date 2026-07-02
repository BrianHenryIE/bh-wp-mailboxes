<?php
/**
 * Builds the appropriate New_Email_Interface wrapper for a downloaded email.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Factories;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\New_Email_Local;
use BrianHenryIE\WP_Mailboxes\API\Model\New_Email_Remote;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

/**
 * Creates a New_Email_Local or New_Email_Remote wrapper based on the account's connection capabilities.
 */
class New_Email_Factory {

	/**
	 * Wrap a downloaded email; remote-capable when the account's connection supports fetching.
	 *
	 * @param API_Interface    $api     The main API instance.
	 * @param BH_Email_Account $account The account the email belongs to; its connection determines local vs remote.
	 * @param BH_Email         $email   The downloaded email.
	 */
	public function make(
		API_Interface $api,
		BH_Email_Account $account,
		BH_Email $email,
	): New_Email_Interface {

		$connection_class = $account->connection_type_class;

		$interfaces = class_implements( $connection_class );

		if ( in_array( Supports_Fetching::class, $interfaces, true ) ) {
			return new New_Email_Remote(
				email: $email,
				api: $api,
			);
		} else {
			return new New_Email_Local(
				email: $email,
				api: $api,
			);
		}
	}
}

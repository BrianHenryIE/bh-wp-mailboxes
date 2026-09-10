<?php
/**
 * A connection that may renew its credentials while in use (e.g. an expired OAuth access token).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;

/**
 * After using the connection, the API asks for renewed credentials and saves them to the credentials store.
 */
interface Refreshes_Credentials {

	/**
	 * The credentials as renewed during this request, or null when they are unchanged since {@see Requires_Credentials::set_credentials()}.
	 */
	public function get_refreshed_credentials(): ?Account_Credentials_Interface;
}

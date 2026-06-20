<?php
/**
 * Interface for email providers that need credentials.
 *
 * The thinking is AWS SES->SNS -> WP REST is a passive receiver where the object here doesn't connect to anything.
 * Similarly, Cloudflare email routing -> Cloudflare Worker -> WP REST would require an application password on the
 * other end but no credentials here.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;

/**
 * Always used with {@see Email_Provider_Interface}.
 */
interface Requires_Credentials {

	/**
	 * Set credentials (and presumably connect if relevant).
	 *
	 * @param Account_Credentials_Interface $credentials From the `bh_wp_mailboxes_credentials` filter.
	 */
	public function set_credentials( Account_Credentials_Interface $credentials ): void;
}

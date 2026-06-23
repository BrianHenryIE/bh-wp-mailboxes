<?php
/**
 * Models a Google client_secret.json containing both an `installed` and a `web` OAuth client.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model;

/**
 * Value object pairing the `installed` (Desktop-app) and `web` (Web-application) OAuth clients.
 */
readonly class Client_Secret {

	/**
	 * Constructor.
	 *
	 * @param OAuth_Client_Credentials $installed The Desktop-app (`installed`) OAuth client for CLI authentication.
	 * @param OAuth_Client_Credentials $web       The Web-application (`web`) OAuth client for web-redirect authentication.
	 */
	public function __construct(
		public OAuth_Client_Credentials $installed,
		public OAuth_Client_Credentials $web,
	) {
	}
}

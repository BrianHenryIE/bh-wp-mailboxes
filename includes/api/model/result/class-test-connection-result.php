<?php
/**
 * Result of validating an account's credentials by connecting to the server.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model\Result;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;

/**
 * Returned by {@see API_Interface::test_connection()}.
 */
readonly class Test_Connection_Result {

	/**
	 * Constructor.
	 *
	 * @param bool   $success Whether the connection and authentication succeeded.
	 * @param string $message Human-readable result, suitable for display in the settings UI.
	 */
	public function __construct(
		public bool $success,
		public string $message,
	) {}
}

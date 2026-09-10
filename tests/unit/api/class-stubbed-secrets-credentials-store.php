<?php
/**
 * Test double for {@see Secrets_Credentials_Store} whose availability is set by the test.
 *
 * Whether the API's functions exist is process-wide state (WP_Mock cannot undefine a function once a
 * test has mocked it), so the check is stubbed here.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

/**
 * Reports availability from a property rather than function_exists().
 */
class Stubbed_Secrets_Credentials_Store extends Secrets_Credentials_Store {

	/**
	 * What is_available() reports.
	 *
	 * @var bool
	 */
	public bool $available = true;

	public function is_available(): bool {
		return $this->available;
	}
}

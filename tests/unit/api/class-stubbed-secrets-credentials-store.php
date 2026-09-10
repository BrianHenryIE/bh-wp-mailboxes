<?php
/**
 * Test double for {@see Secrets_Credentials_Store} whose availability is set by the test.
 *
 * WP_Mock cannot undefine the Secrets API functions once a test has mocked them, so the
 * function_exists() check cannot be exercised in both directions within one process.
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

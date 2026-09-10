<?php
/**
 * Test double for {@see Secrets_Credentials_Store} whose Secrets API load is stubbed and counted.
 *
 * The loader keeps process-wide state, so the store's load_api() seam is answered from a property
 * here, and calls are counted to assert the API is loaded lazily.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

/**
 * Answers load_api() from a property and counts the calls.
 */
class Stubbed_Secrets_Credentials_Store extends Secrets_Credentials_Store {

	/**
	 * What load_api() reports.
	 *
	 * @var bool
	 */
	public bool $load_result = true;

	/**
	 * How many times load_api() has been called.
	 *
	 * @var int
	 */
	public int $load_calls = 0;

	protected function load_api(): bool {
		++$this->load_calls;
		return $this->load_result;
	}

	/**
	 * A provider to hand out from get_provider() without injecting one through the constructor
	 * (an injected provider makes the store available without loading the API, which would hide
	 * whether load_api() was consulted).
	 *
	 * @var ?\WP_Secrets_Provider
	 */
	public ?\WP_Secrets_Provider $stub_provider = null;

	protected function get_provider(): \WP_Secrets_Provider {
		return $this->stub_provider ?? parent::get_provider();
	}

	/**
	 * Expose the lazily built provider.
	 */
	public function provider(): \WP_Secrets_Provider {
		return $this->get_provider();
	}
}

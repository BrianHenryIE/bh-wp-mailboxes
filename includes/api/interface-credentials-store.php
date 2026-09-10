<?php
/**
 * Where an account's credentials are kept.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use InvalidArgumentException;
use RuntimeException;

/**
 * Save, load and discard the credentials for an email account.
 *
 * The default implementation is {@see Secrets_Credentials_Store}, backed by the WordPress Secrets API.
 */
interface Credentials_Store_Interface {

	/**
	 * Whether the store can be used on this site (e.g. the Secrets API is loaded).
	 */
	public function is_available(): bool;

	/**
	 * The account's saved credentials, or null when none are saved (or they could not be read; that is logged).
	 *
	 * @param BH_Email_Account $account The account.
	 */
	public function get( BH_Email_Account $account ): ?Account_Credentials_Interface;

	/**
	 * Save (or replace) the account's credentials.
	 *
	 * @param BH_Email_Account              $account     The account.
	 * @param Account_Credentials_Interface $credentials The credentials; the store reads and serialises them.
	 *
	 * @throws InvalidArgumentException When the credentials type is not one the store can serialise.
	 * @throws RuntimeException When the store is unavailable or the write fails.
	 */
	public function save( BH_Email_Account $account, Account_Credentials_Interface $credentials ): void;

	/**
	 * Discard the account's credentials. Deleting credentials that do not exist is not an error.
	 *
	 * @param BH_Email_Account $account The account.
	 *
	 * @throws RuntimeException When the store is unavailable or the delete fails.
	 */
	public function delete( BH_Email_Account $account ): void;
}

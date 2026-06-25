<?php
/**
 * Main API interface for bh-wp-mailboxes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Account_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Mailbox_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Delete_Old_Emails_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Test_Connection_Result;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTimeInterface;

/**
 * Defines the public API for interacting with email mailboxes.
 */
interface API_Interface {

	/**
	 * Returns the most recently downloaded emails.
	 *
	 * @param int $number Maximum number of emails to return.
	 *
	 * @return BH_Email[]
	 */
	public function get_downloaded_emails( int $number ): array;

	/**
	 * Deletes locally-stored emails older than the configured retention period.
	 */
	public function delete_old_emails(): Delete_Old_Emails_Result;

	/**
	 * Fetches new emails from all configured mailboxes and saves them.
	 */
	public function check_email(): Check_Mailbox_Result;

	/**
	 * Fetches new emails for a single account and saves them.
	 *
	 * @param BH_Email_Account   $account The account to check.
	 * @param ?DateTimeInterface $since   The time to check emails since (default to: last_successful_login_time | 7 days).
	 */
	public function check_email_for_account( BH_Email_Account $account, ?DateTimeInterface $since = null ): Check_Email_Account_Result;

	/**
	 * Mark the email as read on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as read.
	 */
	public function mark_email_read( BH_Email $email ): BH_Email;

	/**
	 * Mark the email as unread on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as unread.
	 */
	public function mark_email_unread( BH_Email $email ): BH_Email;

	/**
	 * Delete the email on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to delete on the server.
	 */
	public function delete_email_on_server( BH_Email $email ): BH_Email;

	/**
	 * Change an email's local status, recording the change in its log.
	 *
	 * @param BH_Email $email        The email to update.
	 * @param string   $local_status The new local (WordPress post) status.
	 */
	public function update_email_local_status( BH_Email $email, string $local_status ): BH_Email;

	/**
	 * Insert a WooCommerce-style log note (wp comment) on the email post.
	 *
	 * TODO: abstract post_id and return BH_Email with BH_Email::$notes array.
	 *
	 * @param int    $post_id The email CPT post ID.
	 * @param string $message The note text.
	 * @param string $level   Log level: `info`, `notice`, `warning`, or `error`.
	 */
	public function insert_email_log_note( int $post_id, string $message, string $level = 'info' ): void;

	/**
	 * Return the email account for an email post, or null if the post/parent was deleted.
	 *
	 * @param BH_Email $email The email whose account to resolve.
	 */
	public function get_email_account_for_email( BH_Email $email ): ?BH_Email_Account;

	/**
	 * Fetch the live read status from the remote server for an email.
	 *
	 * Makes a remote API call via the email's provider. Returns null when the status cannot be
	 * determined (no account, provider, or remote coordinates, or the provider cannot read status).
	 *
	 * @param BH_Email $email The email to query.
	 */
	public function get_remote_read_status( BH_Email $email ): ?bool;

	/**
	 * Return the email fetcher for a given account, or null when no provider is known.
	 *
	 * @param BH_Email_Account $email_account The account to find a fetcher for.
	 */
	public function get_provider_for_email_account( BH_Email_Account $email_account ): ?Email_Provider_Interface;

	/**
	 * Returns all configured email accounts indexed by email address.
	 *
	 * @return BH_Email_Account[]
	 */
	public function get_email_accounts(): array;

	/**
	 * Return the settings used to configure the instance.
	 */
	public function get_settings(): BH_WP_Mailboxes_Settings_Interface;

	/**
	 * Validate an account's credentials by connecting to the server.
	 *
	 * Intended for the settings-save flow. Pass `$credentials` to validate candidate credentials
	 * before the account is saved; otherwise they are resolved via the `bh_wp_mailboxes_credentials`
	 * filter.
	 *
	 * @param BH_Email_Account               $account     The account whose provider to connect with.
	 * @param ?Account_Credentials_Interface $credentials Candidate credentials, or null to resolve them.
	 */
	public function test_connection( BH_Email_Account $account, ?Account_Credentials_Interface $credentials = null ): Test_Connection_Result;
}

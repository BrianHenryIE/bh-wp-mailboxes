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
	 * Wrap a newly saved email and announce it via the `bh_wp_mailboxes_new_email` action.
	 *
	 * Callers must not announce duplicates: idempotent-retry suppression is the caller's
	 * responsibility, since only the caller knows whether the email was already stored.
	 *
	 * @param BH_Email_Account $account The account the email was filed under.
	 * @param BH_Email         $email   The newly saved email.
	 *
	 * @return New_Email_Interface The wrapper object the action was fired with.
	 */
	public function alert_new_email( BH_Email_Account $account, BH_Email $email ): New_Email_Interface;

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
	 * Makes a remote API call via the email's connection. Returns null when the status cannot be
	 * determined (no account, connection, or remote coordinates, or the connection cannot read status).
	 *
	 * @param BH_Email $email The email to query.
	 */
	public function get_remote_read_status( BH_Email $email ): ?bool;

	/**
	 * Return the email fetcher for a given account, or null when no connection is known.
	 *
	 * @param BH_Email_Account $email_account The account to find a fetcher for.
	 */
	public function get_connection_for_email_account( BH_Email_Account $email_account ): ?Email_Connection_Interface;

	/**
	 * Returns all configured email accounts indexed by email address.
	 *
	 * @return BH_Email_Account[]
	 */
	public function get_email_accounts(): array;

	/**
	 * Add or update an email account configuration; the email address is the account's unique id.
	 *
	 * @see API::configure_email_account()
	 *
	 * @param string  $email_address                      The mailbox address.
	 * @param ?string $display_name                       Human-readable account name. Required when creating.
	 * @param ?string $connection_type_class              Connection class (class-string<Email_Connection_Interface>). Required when creating.
	 * @param ?string $from_address_regex_filter          Optional regex to filter incoming senders.
	 * @param ?string $body_identifier_regex_filter       Optional regex to filter email bodies.
	 * @param ?string $after_download_remote_email_action One of: nothing, mark_read, delete.
	 * @param ?int    $delete_local_emails_after_n_days   Days before locally-saved emails are purged.
	 */
	public function configure_email_account(
		string $email_address,
		?string $display_name = null,
		?string $connection_type_class = null,
		?string $from_address_regex_filter = null,
		?string $body_identifier_regex_filter = null,
		?string $after_download_remote_email_action = null,
		?int $delete_local_emails_after_n_days = null,
	): BH_Email_Account;

	/**
	 * Enable or disable an email account without deleting it.
	 *
	 * @param string $email_address The mailbox address of the account.
	 * @param bool   $active        True to check the account on cron; false to skip it.
	 *
	 * @return ?BH_Email_Account The updated account, or null when no account exists for the address.
	 */
	public function set_email_account_active( string $email_address, bool $active ): ?BH_Email_Account;

	/**
	 * Delete an email account configuration and its saved credentials. Locally saved emails are not deleted.
	 *
	 * @param string $email_address The mailbox address of the account to delete.
	 *
	 * @return bool True when the account was deleted; false when no account exists for the address.
	 */
	public function delete_email_account( string $email_address ): bool;

	/**
	 * The account's saved credentials, or null when none are saved.
	 *
	 * @param BH_Email_Account $account The account.
	 */
	public function get_account_credentials( BH_Email_Account $account ): ?Account_Credentials_Interface;

	/**
	 * Save (or replace) the account's credentials. Stored encrypted via the WordPress Secrets API.
	 *
	 * @param BH_Email_Account              $account     The account.
	 * @param Account_Credentials_Interface $credentials IMAP ({@see \BrianHenryIE\WP_Mailboxes\Connections\Imap\IMAP_Credentials_Interface}) or Gmail ({@see \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface}) credentials.
	 *
	 * @throws \InvalidArgumentException When the credentials type cannot be stored.
	 * @throws \RuntimeException When the store is unavailable or the write fails.
	 */
	public function save_account_credentials( BH_Email_Account $account, Account_Credentials_Interface $credentials ): void;

	/**
	 * Discard the account's saved credentials. Also done by {@see self::delete_email_account()}.
	 *
	 * @param BH_Email_Account $account The account.
	 *
	 * @throws \RuntimeException When the store is unavailable or the delete fails.
	 */
	public function delete_account_credentials( BH_Email_Account $account ): void;

	/**
	 * Return the settings used to configure the instance.
	 */
	public function get_settings(): BH_WP_Mailboxes_Settings_Interface;

	/**
	 * Validate an account's credentials by connecting to the server.
	 *
	 * Intended for the settings-save flow. Pass `$credentials` to validate candidate credentials
	 * before they are saved; otherwise the account's saved credentials are used.
	 *
	 * @param BH_Email_Account               $account     The account whose connection to connect with.
	 * @param ?Account_Credentials_Interface $credentials Candidate credentials, or null to resolve them.
	 */
	public function test_connection( BH_Email_Account $account, ?Account_Credentials_Interface $credentials = null ): Test_Connection_Result;
}

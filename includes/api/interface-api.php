<?php
/**
 * Main API interface for bh-wp-mailboxes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;

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
	 *
	 * @return array{success:bool}
	 */
	public function delete_old_emails(): array;

	/**
	 * Fetches new emails from all configured mailboxes and saves them.
	 *
	 * @return array{success:bool}
	 */
	public function check_email(): array;

	/**
	 * Mark the email as read on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as read.
	 */
	public function mark_email_read( BH_Email $email ): void;

	/**
	 * Mark the email as unread on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as unread.
	 */
	public function mark_email_unread( BH_Email $email ): void;

	/**
	 * Delete the email on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to delete on the server.
	 */
	public function delete_email_on_server( BH_Email $email ): void;

	/**
	 * Insert a WooCommerce-style log note (wp comment) on the email post.
	 *
	 * @param int    $post_id The email CPT post ID.
	 * @param string $message The note text.
	 */
	public function insert_email_log_note( int $post_id, string $message ): void;

	/**
	 * Return the email account for an email post, or null if the post/parent was deleted.
	 *
	 * @param BH_Email $email The email whose account to resolve.
	 */
	public function get_email_account_for_email( BH_Email $email ): ?BH_Email_Account;

	/**
	 * Return the email fetcher for a given account, or null when no provider is known.
	 *
	 * @param BH_Email_Account $email_account The account to find a fetcher for.
	 */
	public function get_provider_for_email_account( BH_Email_Account $email_account ): ?Email_Fetcher_Interface;

	/**
	 * Returns all configured email accounts indexed by email address.
	 *
	 * @return BH_Email_Account[]
	 */
	public function get_email_accounts(): array;
}

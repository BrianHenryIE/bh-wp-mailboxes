<?php
/**
 * Persists and retrieves emails, abstracting the underlying storage.
 *
 * Callers depend on this interface rather than the concrete WordPress-posts implementation, so they
 * need no knowledge of wp_posts, guids, slugs or `$wpdb`.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Repository for stored emails.
 */
interface Email_Repository_Interface {

	/**
	 * Returns a stored email by its ID.
	 *
	 * @param int $post_id The stored email's ID.
	 *
	 * @throws \InvalidArgumentException When no email is found with the given ID.
	 */
	public function find_by_post_id( int $post_id ): BH_Email;

	/**
	 * Returns the most recently stored emails.
	 *
	 * @param int $limit Maximum number of emails to return.
	 *
	 * @return BH_Email[]
	 */
	public function find_recent( int $limit = 200 ): array;

	/**
	 * Delete all emails stored before the given cutoff.
	 *
	 * @param DateTimeInterface $cutoff Delete emails older than this datetime.
	 *
	 * @return int Number of emails deleted.
	 */
	public function delete_older_than( DateTimeInterface $cutoff ): int;

	/**
	 * Whether an email is already stored for this account + Message-ID.
	 *
	 * @param string $account_email_address The account the email is filed under.
	 * @param string $message_id            The email Message-ID.
	 */
	public function is_post_for_message_id( string $account_email_address, string $message_id ): bool;

	/**
	 * Returns the number of stored emails for a given account.
	 *
	 * @param BH_Email_Account $email_account The mailbox account.
	 */
	public function count_for_account_email( BH_Email_Account $email_account ): int;

	/**
	 * Stores a new email (deduplicating against an already-stored copy).
	 *
	 * @param Fetched_Email                      $fetched_email    The email plus its remote coordinates and read state.
	 * @param BH_WP_Mailboxes_Settings_Interface $mailbox_settings The mailboxes settings.
	 * @param BH_Email_Account                   $email_account    The email account settings.
	 * @param ?Private_Uploads_API_Interface     $private_uploads  When present, email attachments are saved to private uploads.
	 *
	 * @throws \Exception When the email cannot be stored.
	 */
	public function save_new(
		Fetched_Email $fetched_email,
		BH_WP_Mailboxes_Settings_Interface $mailbox_settings,
		BH_Email_Account $email_account,
		?Private_Uploads_API_Interface $private_uploads = null
	): BH_Email;

	/**
	 * Stores a collection of newly-fetched emails.
	 *
	 * @param Collection<int, Fetched_Email>     $all_new_account_emails The emails to store.
	 * @param BH_WP_Mailboxes_Settings_Interface $mailboxes              The mailboxes settings.
	 * @param BH_Email_Account                   $email_account          The email account settings.
	 * @param ?Private_Uploads_API_Interface     $private_uploads        When present, attachments are saved to private uploads.
	 *
	 * @return BH_Email[]
	 */
	public function save_all(
		Collection $all_new_account_emails,
		BH_WP_Mailboxes_Settings_Interface $mailboxes,
		BH_Email_Account $email_account,
		?Private_Uploads_API_Interface $private_uploads = null
	): array;

	/**
	 * Updates a stored email's mutable status fields.
	 *
	 * @param BH_Email $email             The email to update.
	 * @param ?string  $local_status      The new local status, or null to leave unchanged.
	 * @param ?bool    $is_remote_read    The new remote read state, or null to leave unchanged.
	 * @param ?bool    $is_remote_deleted The new remote deleted state, or null to leave unchanged.
	 *
	 * @throws \Exception When the email cannot be updated.
	 */
	public function update(
		BH_Email $email,
		?string $local_status = null,
		?bool $is_remote_read = null,
		?bool $is_remote_deleted = null,
	): BH_Email;

	/**
	 * Record a log note (e.g. WooCommerce-style order note) against a stored email.
	 *
	 * @param Saved_Post           $saved_post  The email to log against.
	 * @param string               $message     The note text.
	 * @param bool                 $is_internal Whether the note is internal-only.
	 * @param array<string, mixed> $meta        Values changed.
	 * @param string               $level       Log level: `info`, `notice`, `warning`, or `error`.
	 */
	public function log( Saved_Post $saved_post, string $message, bool $is_internal = false, array $meta = array(), string $level = 'info' ): void;
}

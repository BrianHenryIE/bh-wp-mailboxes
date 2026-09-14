<?php
/**
 * One stored email paired with the operations a consumer can perform on it locally.
 *
 * Handed to consumers by the `bh_wp_mailboxes_new_email` action and by `API::get_email()`; the REST
 * command routes are thin wrappers over it. Fluent and immutable: every operation returns a new instance
 * wrapping the email as it is after the operation, so always use the returned value for further calls.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Controller;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

interface Email_Controller_Interface {

	/**
	 * Get the immutable email itself.
	 */
	public function get_email(): BH_Email;

	/**
	 * Set the local status – 'processed' when the email was expected and handled, 'saved' when expected,
	 * handled, and should be preserved.
	 *
	 * Emails not set to 'saved' will be deleted per the {@see Email_Account_Settings_Interface::get_delete_emails_days()} config.
	 *
	 * Consumers should endeavor to also ::add_local_note() on every change (the primary point of this
	 * library is to add visibility into email processing for debugging).
	 *
	 * @param string $local_status 'new'|'processed'|'saved'.
	 */
	public function update_local_status( string $local_status = 'processed' ): static;

	/**
	 * Add a note that will be displayed on the single email view's log.
	 *
	 * E.g. "email did not match any Venmo payment email regex".
	 *
	 * @param string              $message Message text (rendered through `wp_kses_post()`).
	 * @param string              $level   'info'|'notice'|'warning'|'error'.
	 * @param array<string,mixed> $context Arbitrary serializable data, stored with the note.
	 */
	public function add_local_note( string $message, string $level = 'notice', array $context = array() ): static;

	/**
	 * Move the local email post to the trash. Its attachments and log notes are kept, so it can be restored.
	 *
	 * Antithetical to logging the emails, but available to the consumers.
	 */
	public function trash_local_email_post(): void;

	/**
	 * Permanently delete the local email post, with its attachments (posts and files) and log notes.
	 */
	public function delete_local_email_post(): void;
}

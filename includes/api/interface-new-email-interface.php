<?php
/**
 * A downloaded email wrapped for consumers, with local-status and logging helpers.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;

interface New_Email_Interface {

	/**
	 * Get the immutable email downloaded.
	 */
	public function get_email(): BH_Email;

	/**
	 * Set the post_status – 'processed' when the email was expected and handled, 'saved' when expected, handled, and should be preserved.
	 *
	 * Emails not set to 'saved' will be deleted per the {@see Email_Account_Settings_Interface::get_delete_emails_days()} config.
	 *
	 * Consumers should endeavor to also ::add_local_note() on every change (the primary point of this library is to add visibility into email processing for debugging).
	 *
	 * @param string $local_status 'new'|'processed'|'saved'.
	 */
	public function update_local_status( string $local_status = 'processed' ): self;

	/**
	 * Add a note that will be displayed on the single email view's log.
	 *
	 * E.g. "email did not match any Venmo payment email regex".
	 *
	 * @param string              $message Message text. (hrefs should be possible, not sure what else yet – `wp_kses_post()`?).
	 * @param string              $level   'info'|'notice'|'warning'|error'.
	 * @param array<string,mixed> $context Arbitrary serializable data.
	 */
	public function add_local_note( string $message, string $level = 'notice', array $context = array() ): self;

	/**
	 * Trash the local email immediately.
	 *
	 * Antithetical to logging the emails, but available to the consumers.
	 *
	 * TODO: make sure comments and attachments get deleted too.
	 * TODO: also don't use wp_delete_post here.
	 */
	public function trash_locally(): self;
}

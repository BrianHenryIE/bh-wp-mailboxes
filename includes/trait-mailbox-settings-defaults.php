<?php
/**
 * Defaults to accompany Mailbox_Settings_Interface.
 *
 * `class My_Mailbox implements Mailbox_Settings_Interface { use Mailbox_Settings_Trait ...`
 */

namespace BrianHenryIE\WP_Mailboxes;

trait Mailbox_Settings_Defaults_Trait {

	public function after_download_email_action(): string {
		return 'nothing';
	}

	/**
	 * Only include emails whose "from" email address includes this regex.
	 * Be careful when using with forwarded emails: the original sender will be lost.
	 *
	 * @return string|null
	 */
	public function get_from_email_regex(): ?string {
		return null;
	}

	/**
	 * Only include messages whose body includes this regex.
	 *
	 * @return string|null
	 */
	public function get_identifier_regex(): ?string {
		return null;
	}

	/**
	 * Delete emails after 7 days.
	 *
	 * @return int|null
	 */
	public function get_delete_emails_days(): ?int {
		return 7;
	}

	/**
	 * Whether this mailbox supports marking emails as read/unread on the server.
	 */
	public function can_mark_read(): bool {
		return false;
	}

	/**
	 * Whether this mailbox supports deleting emails on the server.
	 */
	public function can_delete_on_server(): bool {
		return false;
	}
}

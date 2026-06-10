<?php
/**
 * A mailbox is a collection of email_accounts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Saved_Post;

/**
 * Represents a saved email account backed by a WordPress post.
 */
readonly class BH_Email_Account implements Saved_Post, Email_Account_Settings_Interface {

	/**
	 * @param int          $post_id The WordPress post ID for this email account.
	 * @param string       $post_type
	 * @param class-string $provider_type_class
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $status,
		public string $provider_type_class,
		public string $email_address,
		public string $display_name,
		public ?string $from_address_regex_filter,
		public ?string $body_identifier_regex_filter,
		public ?string $after_download_email_action,
		public ?int $delete_emails_after_n_days,
		public ?\DateTimeInterface $last_successful_login_time, // Not exactly last email received time.
		public ?\DateTimeInterface $last_failed_login_time,
	) {
	}

	/**
	 * Returns the WordPress post ID.
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}

	public function is_active(): bool {
		return $this->status === 'active';
	}

	public function get_account_email_address(): string {
		return $this->email_address;
	}

	public function get_account_unique_friendly_name(): string {
		return $this->display_name;
	}

	public function after_download_email_action(): string {
		return $this->after_download_email_action;
	}

	public function get_from_email_regex(): ?string {
		return $this->from_address_regex_filter;
	}

	public function get_body_identifier_regex(): ?string {
		return $this->body_identifier_regex_filter;
	}

	public function get_delete_emails_days(): ?int {
		return $this->delete_emails_after_n_days;
	}
}

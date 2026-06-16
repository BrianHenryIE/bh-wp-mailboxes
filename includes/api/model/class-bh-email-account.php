<?php
/**
 * A mailbox is a collection of email_accounts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\Email_Provider_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Saved_Post;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Cron;
use DateTimeInterface;

/**
 * Represents a saved email account backed by a WordPress post.
 */
readonly class BH_Email_Account implements Saved_Post, Email_Account_Settings_Interface {

	/**
	 * Constructor.
	 *
	 * @param int                                    $post_id The WordPress post ID for this email account.
	 * @param string                                 $post_type The post type configured by the plugin author that is used to save accounts.
	 * @param string                                 $local_status The post status: bh_email_ac_active|bh_email_ac_inactive...
	 * @param class-string<Email_Provider_Interface> $provider_type_class When this account is being processed, what class should be used to fetch emails.
	 * @param string                                 $email_address The email address for display.
	 * @param string                                 $display_name The account name for display.
	 * @param ?string                                $from_address_regex_filter Regular expression to match sender address.
	 * @param ?string                                $body_identifier_regex_filter Regular expression to match against email content.
	 * @param ?string                                $after_download_remote_email_action Action to execute against server after downloading an email.
	 * @param ?int                                   $delete_local_emails_after_n_days How long to store emails before auto-delete.
	 * @param ?DateTimeInterface                     $last_checked_time Record of last checked time.
	 * @param ?DateTimeInterface                     $last_successful_login_time Record of last successful connection time.
	 * @param ?DateTimeInterface                     $last_failed_login_time Record of last failed attempt.
	 *
	 * @see BH_Email_Account_CPT::register_post_statuses()
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $local_status,
		public string $provider_type_class,
		public string $email_address,
		public string $display_name,
		public ?string $from_address_regex_filter,
		public ?string $body_identifier_regex_filter,
		public ?string $after_download_remote_email_action,
		public ?int $delete_local_emails_after_n_days,
		public ?DateTimeInterface $last_checked_time,
		public ?DateTimeInterface $last_successful_login_time, // Not exactly last email received time.
		public ?DateTimeInterface $last_failed_login_time,
	) {
	}

	/**
	 * Returns the WordPress post ID.
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}

	/**
	 * Should the account be checked for emails on cron?
	 */
	public function is_active(): bool {
		return 'bh_email_ac_active' === $this->local_status;
	}

	/**
	 * Friendly email address for display (independent to credentials).
	 */
	public function get_account_email_address(): string {
		return $this->email_address;
	}

	/**
	 * Display name for UI and logs.
	 */
	public function get_account_display_friendly_name(): string {
		return $this->display_name;
	}

	/**
	 * What to do with the email on the server after downloading it to WordPress.
	 *
	 * TODO: enum.
	 */
	public function after_download_remote_email_action(): string {
		return $this->after_download_remote_email_action ?? 'nothing';
	}

	/**
	 * A regex to run against the email from address – only save emails that match this regex.
	 */
	public function get_from_email_regex(): ?string {
		return $this->from_address_regex_filter;
	}

	/**
	 * A regex run against the email body – if it matches, the email is saved, otherwise it is ignored.
	 */
	public function get_body_identifier_regex(): ?string {
		return $this->body_identifier_regex_filter;
	}

	/**
	 * How many days should the emails be stored before deleting?
	 *
	 * Use status `bh_email_saved` {@see BH_Email_CPT::register_post_statuses()} to preserve the email.
	 *
	 * @see Cron::background_delete_local_emails()
	 */
	public function get_delete_emails_days(): ?int {
		return $this->delete_local_emails_after_n_days;
	}

	/**
	 * The WordPress posts' table post_type this object is saved as.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_email_accounts_cpt_underscored_20()
	 * @see BH_Email_Account_CPT::register_cpt()
	 */
	public function get_post_type(): string {
		return $this->post_type;
	}
}

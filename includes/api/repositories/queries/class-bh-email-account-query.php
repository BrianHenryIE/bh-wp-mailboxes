<?php
/**
 * Strongly typed object for querying `BH_Email_Account`s in wp_posts table.
 *
 * A mapping of domain terms to WP_Post columns + meta fields.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

use BrianHenryIE\WP_Mailboxes\API\Email_Fetcher_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTimeInterface;

/**
 * Query object for BH_Email CPT records.
 */
readonly class BH_Email_Account_Query extends WP_Post_Query_Abstract {

	/**
	 * @param string             $post_type As defined in {@see BH_WP_Mailboxes_Settings_Interface::get_email_accounts_cpt_underscored_20()}.
	 * @param ?string            $provider_type_class The {@see Email_Fetcher_Interface} implementation used for this account.
	 * @param ?int               $post_id WordPress post table ID.
	 * @param ?string            $email_address Email address for display and search.
	 * @param ?string            $status One of "bh_email_ac_active"|"bh_email_ac_inactive"...
	 * @param ?DateTimeInterface $last_checked_time The last time an attempt was made to fetch emails.
	 * @param ?string            $display_name Friendly account name for UI.
	 * @param ?string            $from_address_regex_filter Only emails whose from address matches this regex will be saved.
	 * @param ?string            $body_identifier_regex_filter Only emails whose body matches this regex will be saved.
	 * @param ?string            $after_download_remote_email_action What to do after downloading the email – delete|mark-read|nothing.
	 * @param ?int               $delete_local_emails_after_n_days How long to keep the emails before cron deletes them.
	 * @param ?DateTimeInterface $last_successful_login_time Record of last successful connection time.
	 * @param ?DateTimeInterface $last_failed_login_time Record of last failure time.
	 */
	public function __construct(
		string $post_type,
		protected ?string $provider_type_class = null,
		protected ?int $post_id = null,
		protected ?string $email_address = null,
		// Mutable.
		protected ?string $status = null,
		protected ?DateTimeInterface $last_checked_time = null,
		protected ?string $display_name = null,
		protected ?string $from_address_regex_filter = null,
		protected ?string $body_identifier_regex_filter = null,
		protected ?string $after_download_remote_email_action = null,
		protected ?int $delete_local_emails_after_n_days = null,
		protected ?DateTimeInterface $last_successful_login_time = null,
		protected ?DateTimeInterface $last_failed_login_time = null,
	) {
		parent::__construct( $post_type );
	}

	/**
	 * Returns the WP_Post field mappings for a BH_Email.
	 *
	 * @see WP_Post_Query_Abstract::get_valid_keys()
	 *
	 * @return array<string,mixed> $map to:from
	 */
	#[\Override]
	protected function get_wp_post_fields(): array {
		return array(
			'post_type'   => $this->post_type,
			'post_status' => $this->status,
			'post_name'   => $this->email_address, // will be auto-sanitized?
		);
	}

	/**
	 * Returns the post meta key/value pairs for a BH_Email.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_meta_input(): array {
		return array(
			'provider_type_class'                => $this->provider_type_class ? str_replace( '\\', '\\\\', $this->provider_type_class ) : null,
			'email_address'                      => $this->email_address, // The post_name is sanitized.
			'display_name'                       => $this->display_name,
			'from_address_regex_filter'          => $this->from_address_regex_filter,
			'body_identifier_regex_filter'       => $this->body_identifier_regex_filter,
			'after_download_remote_email_action' => $this->after_download_remote_email_action,
			'delete_local_emails_after_n_days'   => $this->delete_local_emails_after_n_days,
			'last_checked_time'                  => $this->last_checked_time,
			'last_successful_login_time'         => $this->last_successful_login_time,
			'last_failed_login_time'             => $this->last_failed_login_time,
		);
	}
}

<?php
/**
 * Strongly typed object for querying `BH_Email_Account`s in wp_posts table.
 *
 * A mapping of domain terms to WP_Post columns + meta fields.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

/**
 * Query object for BH_Email CPT records.
 */
readonly class BH_Email_Account_Query extends WP_Post_Query_Abstract {

	public function __construct(
		string $post_type,
		protected ?string $provider_type_class = null,
		protected ?int $post_id = null,
		protected ?string $email_address = null,
		// Mutable.
		protected ?string $status = null,
		protected ?\DateTimeInterface $last_checked_time = null,
		protected ?string $display_name = null,
		protected ?string $from_address_regex_filter = null,
		protected ?string $body_identifier_regex_filter = null,
		protected ?string $after_download_email_action = null,
		protected ?int $delete_emails_after_n_days = null,
		protected ?\DateTimeInterface $last_successful_login_time = null,
		protected ?\DateTimeInterface $last_failed_login_time = null,
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
		// 'post_content' => $this->original_email,
			// 'post_excerpt',
		);
	}

	/**
	 * Returns the post meta key/value pairs for a BH_Email.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_meta_input(): array {
		return array(
			// 'attachment_ids'    => $this->attachment_ids,
			// 'from_address'      => $this->from_address,
			// 'is_read_remote'    => $this->is_read_remote ? 'yes' : 'no', // TODO: don't save anything for null.
			// 'is_deleted_remote' => $this->is_deleted_remote,

			'provider_type_class'          => $this->provider_type_class,
			'email_address'                => $this->email_address, // The post_name is sanitized.
			'display_name'                 => $this->display_name,
			'from_address_regex_filter'    => $this->from_address_regex_filter,
			'body_identifier_regex_filter' => $this->body_identifier_regex_filter,
			'after_download_email_action'  => $this->after_download_email_action,
			'delete_emails_after_n_days'   => $this->delete_emails_after_n_days,
			'last_checked_time'            => $this->last_checked_time,
			'last_successful_login_time'   => $this->last_successful_login_time,
			'last_failed_login_time'       => $this->last_failed_login_time,
		);
	}
}

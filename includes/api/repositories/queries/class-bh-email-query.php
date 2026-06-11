<?php
/**
 * Strongly typed object for querying `BH_Email`s in wp_posts table.
 *
 * A mapping of domain terms to WP_Post columns + meta fields.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

/**
 * Query object for BH_Email CPT records.
 */
readonly class BH_Email_Query extends WP_Post_Query_Abstract {

	/**
	 * Constructor.
	 *
	 * Sometimes these fields are used to fetch, but some are only used to update.
	 *
	 * @param string     $post_type             The CPT slug.
	 * @param string     $account_email_address The mailbox email address (used for guid).
	 * @param string     $email_id              The unique email ID (used for guid).
	 * @param string     $subject               The email subject.
	 * @param string     $from_address          The sender email address.
	 * @param string     $original_email        The raw email content.
	 * @param string     $local_status          The WordPress post status.
	 * @param ?bool      $is_remote_read        Whether the email is marked read on the remote server.
	 * @param ?bool      $is_remote_deleted     Whether the email has been deleted on the remote server.
	 * @param array<int> $attachment_ids     Array of attachment post IDs.
	 */
	public function __construct(
		string $post_type,
		public ?int $post_id = null,
		public ?string $account_email_address = null, // for guid (but not the full URL guid).
		public ?string $email_id = null, // for guid (but not the full URL guid).
		public ?string $subject = null,
		public ?string $from_address = null, // We'll save this in meta because if it matches a user account it is relevant.
		public ?string $original_email = null,
		// post_excerpt // Is there anywhere we need to use this, if so it would be good to strip tags etc here.
		public ?string $local_status = null, // post status.
		public ?bool $is_remote_read = null,
		public ?bool $is_remote_deleted = null,
		public ?array $attachment_ids = null,
	) {
		parent::__construct( $post_type );
	}

	/**
	 * Returns the WP_Post field mappings for a BH_Email.
	 *
	 * @return array<string,mixed> $map to:from
	 */
	#[\Override]
	protected function get_wp_post_fields(): array {
		return array(
			'post_type'    => $this->post_type,
			'post_title'   => $this->subject,
			'post_status'  => $this->local_status,
			// 'post_parent' => , // mailbox id.
			'post_content' => $this->original_email,
			// 'post_excerpt',
			'guid'         => $this->account_email_address && $this->email_id ? $this->guid_for( $this->account_email_address, $this->email_id ) : null,
		);
	}

	/**
	 * Returns the post meta key/value pairs for a BH_Email.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_meta_input(): array {
		return array(
			'attachment_ids'    => $this->attachment_ids,
			'from_address'      => $this->from_address,
			'is_remote_read'    => $this->is_remote_read,
			'is_remote_deleted' => $this->is_remote_deleted,
		);
	}

	/**
	 * Builds the guid URL for the given email ID.
	 *
	 * TODO: test that we're never passing an existing guid, only ever the email id itself.
	 * TODO: this URL should work for admins to load the email.
	 *
	 * @param string $email_id The email message ID.
	 *
	 * @example https://bhwp.ie/my-mailbox/contact@bhwp.ie/q1w2e3r4t5
	 */
	protected function guid_for( string $account_email_address, string $email_id ): string {
		$site_url = get_site_url();
		return sprintf(
			'%s/%s/%s/%s',
			$site_url,
			$this->post_type,
			rawurlencode( $account_email_address ),
			rawurlencode( sanitize_key( $email_id ) )
		);
	}
}

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
 */
readonly class BH_Email_Query extends WP_Post_Query_Abstract {

	/**
	 * Constructor
	 *
	 * Sometimes these fields are used to fetch, but some are only used to update.
	 */
	public function __construct(
		string $post_type,
		public string $account_email_address, // for guid (but not the full URL guid)
		public string $email_id, // for guid (but not the full URL guid)
		public string $subject,
		public string $from_address, // We'll save this in meta because if it matches a user account it is relevant.
		public string $original_email,
		// post_excerpt // Is there anywhere we need to use this, if so it would be good to strip tags etc here.
		public string $local_status, // post status
		public ?bool $is_read_remote,
		public ?bool $is_deleted_remote,
		public array $attachment_ids,
	) {
		parent::__construct( $post_type );
	}

	/**
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
			'guid'         => $this->guid_for( $this->email_id ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function get_meta_input(): array {
		return array(
			'attachment_ids'    => $this->attachment_ids,
			'from_address'      => $this->from_address,
			'is_read_remote'    => $this->is_read_remote ? 'yes' : 'no', // TODO: don't save anything for null.
			'is_deleted_remote' => $this->is_deleted_remote,
		);
	}

	/**
	 * TODO: test that we're never passing an existing guid, only ever the email id itself.
	 * TODO: this URL should work for admins to load the email.
	 *
	 * @example https://bhwp.ie/my-mailbox/contact@bhwp.ie/q1w2e3r4t5
	 */
	protected function guid_for( string $email_id ): string {
		$site_url = get_site_url();
		return sprintf(
			'%s/%s/%s/%s',
			$site_url,
			$this->post_type,
			urlencode( $this->account_email_address ),
			urlencode( sanitize_key( $email_id ) )
		);
	}
}

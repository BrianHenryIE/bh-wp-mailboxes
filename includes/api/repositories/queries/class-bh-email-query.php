<?php
/**
 * Strongly typed object for querying `BH_Email`s in wp_posts table.
 *
 * A mapping of domain terms to WP_Post columns + meta fields.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;

/**
 * Query object for BH_Email CPT records.
 */
readonly class BH_Email_Query extends WP_Post_Query_Abstract {

	/**
	 * Constructor.
	 *
	 * Sometimes these fields are used to fetch, but some are only used to update.
	 *
	 * @param string      $post_type             The CPT slug.
	 * @param ?int        $post_id The email's ID in the WordPress posts table.
	 * @param ?int        $post_parent WP post_id for the BH_Email_Account.
	 * @param ?string     $account_email_address The mailbox email address (used for guid).
	 * @param ?string     $email_id              The unique email ID (used for guid).
	 * @param ?string     $subject               The email subject.
	 * @param ?string     $from_address          The sender email address.
	 * @param ?string     $original_email        The raw email content.
	 * @param ?string     $local_status          The WordPress post status. bh_email_new|bh_email_processed|bh_email_saved...
	 * @param ?bool       $is_remote_read        Whether the email is marked read on the remote server.
	 * @param ?bool       $is_remote_deleted     Whether the email has been deleted on the remote server.
	 * @param ?array<int> $attachment_ids        Array of attachment post IDs.
	 * @param ?string     $remote_uid            Provider-native id (IMAP UID / Gmail message id) for direct lookups.
	 * @param ?string     $remote_folder         IMAP folder/mailbox path the UID belongs to.
	 * @param ?int        $remote_uid_validity   IMAP UIDVALIDITY of the folder when the UID was captured.
	 */
	public function __construct(
		string $post_type,
		public ?int $post_id = null,
		public ?int $post_parent = null,
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
		public ?string $remote_uid = null,
		public ?string $remote_folder = null,
		public ?int $remote_uid_validity = null,
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
			'ID'           => $this->post_id,
			'post_type'    => $this->post_type,
			'post_title'   => $this->subject,
			'post_status'  => $this->local_status,
			'post_parent'  => $this->post_parent, // mailbox id.
			'post_content' => $this->original_email,
			'guid'         => $this->get_guid(),
		);
	}

	/**
	 * Returns the post meta key/value pairs for a BH_Email.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_meta_input(): array {
		return array(
			'attachment_ids'      => $this->attachment_ids,
			'from_address'        => $this->from_address,
			'is_remote_read'      => $this->is_remote_read,
			'is_remote_deleted'   => $this->is_remote_deleted,
			'remote_uid'          => $this->remote_uid,
			'remote_folder'       => $this->remote_folder,
			'remote_uid_validity' => $this->remote_uid_validity,
		);
	}

	/**
	 * The guid uniquely identifies an email by account + Message-ID, so the repository can
	 * deduplicate the same email fetched twice. Returns null when either component is absent.
	 */
	public function get_guid(): ?string {
		if ( null === $this->account_email_address || null === $this->email_id ) {
			return null;
		}
		return Email_WP_Post_Repository::guid_for( $this->post_type, $this->account_email_address, $this->email_id );
	}
}

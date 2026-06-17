<?php
/**
 * Persists and retrieves BH_Email objects as WordPress CPT posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\BH_Email_Query;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Post;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * WordPress post repository for email CPT records.
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string}
 */
class Email_WP_Post_Repository extends WP_Post_Repository_Abstract {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param string           $post_type        The CPT slug, e.g. "my_plugin_emails".
	 * @param BH_Email_Factory $bh_email_factory Factory for creating BH_Email instances.
	 * @param ?LoggerInterface $logger           PSR-3 logger.
	 */
	public function __construct(
		protected string $post_type,
		protected BH_Email_Factory $bh_email_factory,
		?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Hydrate a BH_Email from a WordPress post ID.
	 *
	 * @param int $post_id The WordPress post ID.
	 *
	 * @return BH_Email
	 * @throws InvalidArgumentException When no post is found with the given ID.
	 */
	public function find_by_post_id( int $post_id ): BH_Email {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer, safe to include in exception.
			throw new InvalidArgumentException( "No post found with ID {$post_id}." );
		}
		return $this->bh_email_factory->from_wp_post( $post );
	}

	/**
	 * Return the most recently saved emails.
	 *
	 * @param int $limit Maximum number of emails to return.
	 *
	 * @return BH_Email[]
	 */
	public function find_recent( int $limit = 200 ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $this->post_type,
				'posts_per_page'         => $limit,
				'update_post_meta_cache' => true,
			)
		);

		$emails = array();
		foreach ( $query->get_posts() as $post ) {
			if ( $post instanceof WP_Post ) {
				$emails[] = $this->bh_email_factory->from_wp_post( $post );
			}
		}
		return $emails;
	}

	/**
	 * Delete all emails with a post_date older than the given cutoff.
	 *
	 * @param DateTimeInterface $cutoff Delete emails older than this datetime.
	 *
	 * @return int Number of emails deleted.
	 */
	public function delete_older_than( DateTimeInterface $cutoff ): int {

		$query = new \WP_Query(
			array(
				'post_type'      => $this->post_type,
				'posts_per_page' => -1,
				'date_query'     => array(
					array(
						'before'    => $cutoff->format( 'Y-m-d H:i:s' ),
						'inclusive' => false,
					),
				),
				'fields'         => 'ids',
			)
		);

		$count = 0;
		foreach ( $query->posts as $post_id ) {
			if ( ! is_int( $post_id ) ) {
				continue;
			}
			$result = wp_delete_post( $post_id, true );
			if ( $result instanceof WP_Post ) {
				++$count;
			}
		}

		$this->logger->info(
			"Deleted {$count} local emails older than " . $cutoff->format( 'Y-m-d H:i:s' ) . '.',
			array( 'cutoff' => $cutoff->format( DateTimeInterface::ATOM ) )
		);

		return $count;
	}

	/**
	 * Determine do we already have a post saved for this account+message id.
	 *
	 * @param string $account_email_address The account we file it under.
	 * @param string $message_id The message uid.
	 */
	public function is_post_for_message_id( string $account_email_address, string $message_id ): bool {

		return (bool) $this->find_post_id_by_guid( self::guid_for( $this->post_type, $account_email_address, $message_id ) );
	}

	/**
	 * Query the WordPress posts table for a post with the given guid.
	 *
	 * @param string $guid The guid to search for.
	 *
	 * @return ?int The post ID, or null if not found.
	 */
	protected function find_post_id_by_guid( string $guid ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s", $guid ) );
		return is_numeric( $result ) ? (int) $result : null;
	}

	/**
	 * Returns the number of saved emails for a given account email address.
	 *
	 * The account address is encoded in each email's GUID, so this uses a LIKE
	 * query rather than a meta lookup.
	 *
	 * @param BH_Email_Account $email_account The mailbox, e.g. "contact@example.com".
	 */
	public function count_for_account_email( BH_Email_Account $email_account ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s AND post_status != 'trash' AND post_parent = %s",
				$this->post_type,
				$email_account->get_post_id()
			)
		);
		return (int) $count;
	}

	/**
	 * Saves a new email to the database.
	 *
	 * @param Fetched_Email                      $fetched_email    The email plus its remote coordinates and read state.
	 * @param BH_WP_Mailboxes_Settings_Interface $mailbox_settings The mailboxes settings.
	 * @param BH_Email_Account                   $email_account    The email account settings.
	 * @param ?Private_Uploads_API_Interface     $private_uploads  When present, email attachments are saved to private uploads.
	 *
	 * @return BH_Email
	 * @throws Exception When WordPress fails to create the post.
	 */
	public function save_new(
		Fetched_Email $fetched_email,
		BH_WP_Mailboxes_Settings_Interface $mailbox_settings,
		BH_Email_Account $email_account,
		?Private_Uploads_API_Interface $private_uploads = null
	): BH_Email {

		$email       = $fetched_email->message;
		$coordinates = $fetched_email->coordinates;

		$post_type = $mailbox_settings->get_emails_cpt_underscored_20();

		$attachment_parts                     = $email->getAllAttachmentParts();
		$all_parts                            = $email->getAllParts();
		$non_attachment_parts                 = array_filter(
			$all_parts,
			fn( $part ) => ! in_array( $part, $attachment_parts, true )
		);
		$original_email_no_attachments_string = implode( ' ', $non_attachment_parts );

		$from_header = $email->getHeader( 'From' );
		$sender      = $from_header instanceof AddressHeader ? $from_header->getEmail() ?? '' : '';

		$query = new BH_Email_Query(
			post_type: $post_type,
			post_parent: $email_account->get_post_id(),
			account_email_address: $email_account->get_account_email_address(),
			email_id: $email->getMessageId() ?? '', // TODO: This should never be empty.
			subject: $email->getSubject() ?? '',
			from_address: $sender, // We'll save this in meta because if it matches a user account it is relevant.
			original_email: $original_email_no_attachments_string,
			local_status: 'bh_email_new',
			is_remote_read: $fetched_email->is_remote_read,
			is_remote_deleted: false, // We may immediately delete the email, but the fact it exists in save_new means it exists remotely.
			// `null` records "attachments disabled"; an array (possibly empty) records "attachments enabled".
			attachment_ids: is_null( $private_uploads ) ? null : array(),
			remote_uid: $coordinates->remote_uid,
			remote_folder: $coordinates->folder,
			remote_uid_validity: $coordinates->uid_validity,
		);

		// Deduplicate: the same email (account + Message-ID) may be fetched more than once.
		// Its guid is stable, so if a post already exists we return it rather than inserting a duplicate.
		$guid             = self::guid_for(
			$post_type,
			$email_account->get_account_email_address(),
			$email->getMessageId() ?? ''
		);
		$existing_post_id = $this->find_post_id_by_guid( $guid );
		if ( null !== $existing_post_id ) {
			return $this->find_by_post_id( $existing_post_id );
		}

		$post_id = $this->insert( $query );

		if ( ! is_null( $private_uploads ) ) {
			$attachment_ids = $this->save_attachments( $attachment_parts, $post_id, $private_uploads );
			update_post_meta( $post_id, 'attachment_ids', (string) wp_json_encode( $attachment_ids ) );
		}

		$bh_email = $this->find_by_post_id( $post_id );

		// Record the download in the email's log.
		$this->log( $bh_email, 'Email downloaded.', false, array(), 'info' );

		return $bh_email;
	}

	/**
	 * Save each email attachment into the private uploads directory, returning the created post ids.
	 *
	 * Each attachment is independent: a failure is logged and the others still save, so one bad
	 * attachment never costs us the email.
	 *
	 * @param IMessagePart[]                $attachment_parts The email's attachment parts.
	 * @param int                           $email_post_id    The saved email's post id, used as the attachments' parent.
	 * @param Private_Uploads_API_Interface $private_uploads  The private uploads API.
	 *
	 * @return int[] The post ids of the saved attachments.
	 */
	private function save_attachments( array $attachment_parts, int $email_post_id, Private_Uploads_API_Interface $private_uploads ): array {

		$attachment_ids = array();

		foreach ( $attachment_parts as $part ) {
			$filename = $part->getFilename() ?? 'attachment';
			$tmp_file = wp_tempnam( $filename );

			try {
				$part->saveContent( $tmp_file );

				$result = $private_uploads->move_file_to_private_uploads_and_create_post(
					tmp_file: $tmp_file,
					filename: $filename,
					post_parent_id: $email_post_id,
				);

				$attachment_ids[] = $result->post_id;
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'Failed to save email attachment.',
					array(
						'filename'  => $filename,
						'post_id'   => $email_post_id,
						'exception' => $e,
					)
				);
			} finally {
				// On success the file has been moved away; this only cleans up after a failure.
				if ( file_exists( $tmp_file ) ) {
					wp_delete_file( $tmp_file );
				}
			}
		}

		return $attachment_ids;
	}

	/**
	 * Saves all new emails for an account.
	 *
	 * @param Collection<int, Fetched_Email>     $all_new_account_emails The new emails to save.
	 * @param BH_WP_Mailboxes_Settings_Interface $mailboxes              The mailboxes settings.
	 * @param BH_Email_Account                   $email_account          The email account settings.
	 *
	 * @return array<int, \BrianHenryIE\WP_Mailboxes\API\Model\BH_Email>
	 * @throws Exception When saving an individual email fails.
	 */
	/**
	 * Saves all new emails for an account.
	 *
	 * @param Collection<int, Fetched_Email>     $all_new_account_emails The new emails to save.
	 * @param BH_WP_Mailboxes_Settings_Interface $mailboxes              The mailboxes settings.
	 * @param BH_Email_Account                   $email_account          The email account settings.
	 * @param ?Private_Uploads_API_Interface     $private_uploads        When present, email attachments are saved to private uploads.
	 *
	 * @return array<int, \BrianHenryIE\WP_Mailboxes\API\Model\BH_Email>
	 * @throws Exception When saving an individual email fails.
	 */
	public function save_all(
		Collection $all_new_account_emails,
		BH_WP_Mailboxes_Settings_Interface $mailboxes,
		BH_Email_Account $email_account,
		?Private_Uploads_API_Interface $private_uploads = null
	): array {

		return array_map(
			fn( $new_email ) => $this->save_new( $new_email, $mailboxes, $email_account, $private_uploads ),
			$all_new_account_emails->all()
		);
	}

	/**
	 * Update a property on an email.
	 *
	 * NB: many email properties are not mutable.
	 *
	 * @param BH_Email $email The existing email to update.
	 * @param ?string  $local_status The post_status for the email in WordPress (i.e. not the remote read/unread status).
	 * @param ?bool    $is_remote_read Record of is the email read on the server.
	 * @param ?bool    $is_remote_deleted Record of is the email deleted on the server.
	 *
	 * @throws Exception On failure to save.
	 */
	public function update(
		BH_Email $email,
		?string $local_status = null,
		?bool $is_remote_read = null,
		?bool $is_remote_deleted = null,
	): BH_Email {

		$query = new BH_Email_Query(
			post_type: $email->get_post_type(),
			post_id: $email->post_id,
			local_status: $local_status !== $email->local_status ? $local_status : null,
			is_remote_read: $is_remote_read !== $email->is_remote_read ? $is_remote_read : null,
			is_remote_deleted: $is_remote_deleted !== $email->is_remote_deleted ? $is_remote_deleted : null,
		);

		$args = $query->to_query_array();

		if ( count( $args ) === 2 ) {
			// Only the post_id remains.
			$this->logger->warning( 'Attempted to make a no-op updated' );
			return $email;
		}

		$result = wp_update_post( $args, true );

		if ( is_wp_error( $result ) ) {
			throw new Exception(
				sprintf(
					'Failed to update email post with ID %d: %s',
					intval( $email->post_id ),
					esc_html( $result->get_error_message() )
				)
			);
		}

		/**
		 * Log the local status change. Guarded so updates to other fields (e.g. remote read state,
		 * which pass a null status) do not record a spurious "status changed" entry.
		 */
		if ( ! is_null( $local_status ) && $email->local_status !== $local_status ) {
			$this->log(
				$email,
				sprintf(
					/* translators: 1: previous status, 2: new status */
					__( 'Status changed from "%1$s" to "%2$s".', 'bh-wp-mailboxes' ),
					$email->local_status,
					$local_status
				),
				false,
				array(),
				'info'
			);
		}

		return $this->find_by_post_id( $email->post_id );
	}

	/**
	 * Builds the guid URL for the given email ID.
	 *
	 * TODO: test that we're never passing an existing guid, only ever the email id itself.
	 * TODO: this URL should work for admins to load the email.
	 *
	 * @param string $post_type The CPT type being saved under.
	 * @param string $account_email_address The mailbox email address.
	 * @param string $email_id              The email message ID.
	 *
	 * @example https://bhwp.ie/my-mailbox/contact@bhwp.ie/q1w2e3r4t5
	 */
	public static function guid_for( string $post_type, string $account_email_address, string $email_id ): string {
		$site_url = get_site_url();
		return sprintf(
			'%s/%s/%s/%s',
			$site_url,
			$post_type,
			rawurlencode( $account_email_address ),
			rawurlencode( sanitize_key( $email_id ) )
		);
	}
}

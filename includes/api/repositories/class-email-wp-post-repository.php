<?php
/**
 * Persists and retrieves BH_Email objects as WordPress CPT posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Email_Thread_Linker;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Email_Status_Counts;
use BrianHenryIE\WP_Mailboxes\API\Model\Email_Thread;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Queries\BH_Email_Query;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_Thread_Taxonomy;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use WP_Post;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * WordPress post repository for email CPT records.
 *
 * @phpstan-type WpUpdatePostArray array{ID?: int, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string}
 */
class Email_WP_Post_Repository extends WP_Post_Repository_Abstract implements Email_Repository_Interface {

	use LoggerAwareTrait;

	/**
	 * Groups saved emails into conversation threads.
	 *
	 * @var Email_Thread_Linker
	 */
	protected Email_Thread_Linker $thread_linker;

	/**
	 * Constructor.
	 *
	 * @param string               $post_type        The CPT slug, e.g. "my_plugin_emails".
	 * @param BH_Email_Factory     $bh_email_factory Factory for creating BH_Email instances.
	 * @param ?LoggerInterface     $logger           PSR-3 logger.
	 * @param ?Email_Thread_Linker $thread_linker    Thread linker; built for this post type when omitted (then threads
	 *                                               are matched within the saving account only).
	 */
	public function __construct(
		protected string $post_type,
		protected BH_Email_Factory $bh_email_factory,
		?LoggerInterface $logger = null,
		?Email_Thread_Linker $thread_linker = null,
	) {
		$this->logger        = $logger ?? new NullLogger();
		$this->thread_linker = $thread_linker ?? new Email_Thread_Linker(
			$post_type,
			new BH_Email_Thread_Taxonomy( $post_type, $this->logger ),
			null,
			$this->logger
		);
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
		if ( ! ( $post instanceof WP_Post ) || $post->post_type !== $this->post_type ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer, safe to include in exception.
			throw new InvalidArgumentException( "No email found with ID {$post_id}." );
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
	 * Load the conversation thread an email belongs to: every email sharing its thread term, oldest first.
	 *
	 * Trashed emails are left out (WP_Query's `any` status excludes `trash`), as are emails stored before
	 * threading existed, which have no term.
	 *
	 * @param BH_Email $email The email whose thread to load.
	 */
	public function find_thread( BH_Email $email ): Email_Thread {

		$term_id = $email->thread_term_id;
		if ( is_null( $term_id ) ) {
			return new Email_Thread( term_id: 0, slug: '', emails: array( $email ) );
		}

		$taxonomy = $this->thread_linker->get_taxonomy()->get_taxonomy_name();
		$term     = get_term( $term_id, $taxonomy );
		$slug     = $term instanceof \WP_Term ? $term->slug : '';

		$query = new \WP_Query(
			array(
				'post_type'              => $this->post_type,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Threads are small; the term relationship table is indexed.
				'tax_query'              => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		$emails = array();
		foreach ( $query->get_posts() as $post ) {
			if ( $post instanceof WP_Post ) {
				$emails[] = $post->ID === $email->post_id ? $email : $this->bh_email_factory->from_wp_post( $post );
			}
		}

		// Oldest first by sent date; emails whose Date header could not be parsed fall back to download time.
		usort(
			$emails,
			function ( BH_Email $a, BH_Email $b ): int {
				$a_time = ( $a->sent_at ?? $a->downloaded_at )?->getTimestamp() ?? 0;
				$b_time = ( $b->sent_at ?? $b->downloaded_at )?->getTimestamp() ?? 0;
				return $a_time <=> $b_time ?: $a->post_id <=> $b->post_id;
			}
		);

		return new Email_Thread( term_id: $term_id, slug: $slug, emails: $emails );
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

		return ! is_null( $this->find_post_id_for_message_id( $account_email_address, $message_id ) );
	}

	/**
	 * Find the saved post ID for an account + Message-ID via its (indexed) slug.
	 *
	 * The account+Message-ID key is stored as the post_name, an indexed column, so this matches directly
	 * against the index. (get_page_by_path() can't be used: it only resolves top-level posts, whereas
	 * emails are parented to their account.)
	 *
	 * @param string $account_email_address The account the email is filed under.
	 * @param string $message_id            The email Message-ID.
	 *
	 * @return ?int The post ID, or null if not found.
	 */
	protected function find_post_id_for_message_id( string $account_email_address, string $message_id ): ?int {
		/**
		 * The WordPress database ORM.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE post_name = %s AND post_type = %s LIMIT 1',
				$wpdb->posts,
				self::message_id_slug( $account_email_address, $message_id ),
				$this->post_type
			)
		);
		return is_numeric( $result ) ? (int) $result : null;
	}

	/**
	 * Returns the number of saved (non-trashed) emails for a given account.
	 *
	 * @param BH_Email_Account $email_account The mailbox account.
	 */
	public function count_for_account_email( BH_Email_Account $email_account ): int {
		return $this->count_by_status_for_account_email( $email_account )->total();
	}

	/**
	 * Counts the account's non-trashed emails in each local status with one grouped query.
	 *
	 * Cached in the `counts` object-cache group like core's `wp_count_posts()` (which runs the same
	 * GROUP BY but cannot be scoped to one account). Rather than registering invalidation hooks, the key
	 * carries `wp_cache_get_last_changed( 'posts' )`, as WP_Query's own query cache does: core bumps that
	 * token from `clean_post_cache()` on every post insert, update, trash and delete, so a stale entry is
	 * simply never looked up again.
	 *
	 * @param BH_Email_Account $email_account The mailbox account.
	 */
	public function count_by_status_for_account_email( BH_Email_Account $email_account ): Email_Status_Counts {
		$cache_key = sprintf(
			'bh_email_status_counts:%s:%d:%s',
			$this->post_type,
			$email_account->get_post_id(),
			wp_cache_get_last_changed( 'posts' )
		);
		$cached    = wp_cache_get( $cache_key, 'counts' );
		if ( $cached instanceof Email_Status_Counts ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached below in the `counts` group.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_status, COUNT(*) AS count FROM %i WHERE post_type = %s AND post_status != \'trash\' AND post_parent = %d GROUP BY post_status',
				$wpdb->posts,
				$this->post_type,
				$email_account->get_post_id()
			),
			ARRAY_A
		);

		$counts = array(
			'bh_email_new'       => 0,
			'bh_email_processed' => 0,
			'bh_email_saved'     => 0,
			'other'              => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['post_status'], $row['count'] ) || ! is_string( $row['post_status'] ) || ! is_numeric( $row['count'] ) ) {
				continue;
			}
			$key             = isset( $counts[ $row['post_status'] ] ) ? $row['post_status'] : 'other';
			$counts[ $key ] += (int) $row['count'];
		}

		$status_counts = new Email_Status_Counts(
			new_count: $counts['bh_email_new'],
			processed_count: $counts['bh_email_processed'],
			saved_count: $counts['bh_email_saved'],
			other_count: $counts['other'],
		);

		wp_cache_set( $cache_key, $status_counts, 'counts' );

		return $status_counts;
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
		?Private_Uploads_API_Interface $private_uploads = null // TODO: Is the strict typing allowed when the library is optional?
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

		// Prefer the coordinates' message id: connections without a Message-ID header substitute a
		// stable fallback there (e.g. the REST ingress uses a digest of the raw message), which keeps
		// retries idempotent and keeps two distinct no-Message-ID emails distinct.
		$message_id = '' !== $coordinates->message_id
			? $coordinates->message_id
			: ( $email->getMessageId() ?? '' );

		$query = new BH_Email_Query(
			post_type: $post_type,
			post_parent: $email_account->get_post_id(),
			account_email_address: $email_account->get_account_email_address(),
			email_id: $message_id, // TODO: This should never be empty.
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

		// Deduplicate: the same email (account + Message-ID) may be fetched more than once. The key is
		// stored as the post slug, so if a post already exists we return it rather than inserting a duplicate.
		$existing_post_id = $this->find_post_id_for_message_id(
			$email_account->get_account_email_address(),
			$message_id
		);
		if ( null !== $existing_post_id ) {
			return $this->find_by_post_id( $existing_post_id );
		}

		$post_id = $this->insert( $query );

		if ( ! is_null( $private_uploads ) ) {
			$attachment_ids = $this->save_attachments( $attachment_parts, $post_id, $private_uploads );
			update_post_meta( $post_id, 'attachment_ids', (string) wp_json_encode( $attachment_ids ) );
		}

		// One meta row per referenced id (meta_input cannot write repeated keys), so later emails can find this one.
		foreach ( Email_Thread_Linker::get_header_ids( $email, HeaderConsts::IN_REPLY_TO ) as $id ) {
			add_post_meta( $post_id, Email_Thread_Linker::META_KEY_IN_REPLY_TO, $id );
		}
		foreach ( Email_Thread_Linker::get_header_ids( $email, HeaderConsts::REFERENCES ) as $id ) {
			add_post_meta( $post_id, Email_Thread_Linker::META_KEY_REFERENCES, $id );
		}

		// Threading never blocks saving the email itself.
		try {
			$thread_result = $this->thread_linker->link( $post_id, $message_id, $email, $email_account );
		} catch ( Throwable $exception ) {
			$thread_result = null;
			$this->logger->error(
				'Failed to link email {post_id} into a thread: {error}',
				array(
					'post_id'   => $post_id,
					'error'     => $exception->getMessage(),
					'exception' => $exception,
				)
			);
		}

		$bh_email = $this->find_by_post_id( $post_id );

		// Record the download in the email's log.
		$this->log( $bh_email, 'Email downloaded.', false, array(), 'info' );

		// Merging is irreversible, so note it on every email that was moved.
		if ( ! is_null( $thread_result ) && array() !== $thread_result->merged_post_ids ) {
			$this->log(
				$bh_email,
				sprintf(
					/* translators: %d: number of emails */
					_n( 'Bridged %d email from another thread into this one.', 'Bridged %d emails from another thread into this one.', count( $thread_result->merged_post_ids ), 'bh-wp-mailboxes' ),
					count( $thread_result->merged_post_ids )
				),
				false,
				array( 'merged_post_ids' => $thread_result->merged_post_ids ),
				'notice'
			);
			foreach ( $thread_result->merged_post_ids as $merged_post_id ) {
				try {
					$this->log(
						$this->find_by_post_id( $merged_post_id ),
						sprintf(
							/* translators: %s: email subject */
							__( 'Merged into the thread of "%s".', 'bh-wp-mailboxes' ),
							$bh_email->get_subject()
						),
						false,
						array( 'thread_term_id' => $thread_result->term_id ),
						'notice'
					);
				} catch ( InvalidArgumentException $exception ) {
					$this->logger->debug( 'Merged email {post_id} no longer exists.', array( 'post_id' => $merged_post_id ) );
				}
			}
		}

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

		// wp_tempnam() and move_file_to_private_uploads_and_create_post() live in wp-admin/includes/file.php,
		// which is not loaded during wp-cron or REST requests — the two contexts a fetch usually runs in.
		// Without this, saving an email that has an attachment fatals with "undefined function wp_tempnam()".
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

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
			} catch ( Throwable $e ) {
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
	 * @return array<int, BH_Email>
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
	 * @return array<int, BH_Email>
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

		$args = $query->to_wp_post_array();

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
	 * Builds the stable, slug-safe key that uniquely identifies an email by account + Message-ID.
	 *
	 * Stored as the email's post_name (an indexed column) so the same email fetched twice deduplicates
	 * against the index rather than scanning. The md5 hex (plus prefix) survives sanitize_title()
	 * unchanged and fits within the 200-character post_name column regardless of Message-ID length.
	 *
	 * @param string $account_email_address The mailbox email address.
	 * @param string $email_id              The email Message-ID.
	 */
	public static function message_id_slug( string $account_email_address, string $email_id ): string {
		return 'bh-' . md5( $account_email_address . '|' . $email_id );
	}
}

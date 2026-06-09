<?php
/**
 * Persists and retrieves BH_Email objects as WordPress CPT posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\BH_Email_Query;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Post;
use ZBateson\MailMimeParser\IMessage;

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
	 * @param string           $post_type The CPT slug, e.g. "my_plugin_emails".
	 * @param ?LoggerInterface $logger    PSR-3 logger.
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
	 */
	public function find_by_post_id( int $post_id ): BH_Email {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
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
			"Deleted {$count} emails older than " . $cutoff->format( 'Y-m-d H:i:s' ) . '.',
			array( 'cutoff' => $cutoff->format( DateTimeInterface::ATOM ) )
		);

		return $count;
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

	public function save_new(
		IMessage $email,
		BH_WP_Mailboxes_Settings_Interface $mailboxes,
		Email_Account_Settings_Interface $email_account
	): BH_Email {

		$post_type = $mailboxes->get_cpt_underscored_20();

		$attachment_parts                     = $email->getAllAttachmentParts();
		$all_parts                            = $email->getAllParts();
		$non_attachment_parts                 = array_filter(
			$all_parts,
			fn( $part ) => ! in_array( $part, $attachment_parts, true )
		);
		$original_email_no_attachments_string = implode( ' ', $non_attachment_parts );

		$sender = $email->getHeader( 'From' )?->getEmail() ?? '';

		$query = new BH_Email_Query(
			post_type: $post_type,
			account_email_address: $email_account->get_account_email_address(),
			email_id: $email->getMessageId(),
			subject: $email->getSubject(),
			from_address: $sender, // We'll save this in meta because if it matches a user account it is relevant.
			original_email: $original_email_no_attachments_string,
			local_status: 'new', /** @see BH_Email_CPT::register_post_statuses() */
			is_read_remote: false, // TODO: how to determine is is already read?
			is_deleted_remote: false, // We may immediately delete the email, but the fact it exists in save_new means it exists remotely.
			attachment_ids: array(),
		);

		/** @var WpUpdatePostArray $args */
		$args = $query->to_query_array();

		$filter_name = 'content_save_pre';
		/** @var \WP_Hook[] $wp_filter */
		global $wp_filter;
		$hook             = $wp_filter[ $filter_name ] ?? null;
		$callbacks_before = $hook->callbacks;
		/**
		 * Avoid modifying the original email content during save. Otherwise, the Message-id header value is removed
		 * and parsing the email fails later.
		 *
		 * The following were removed in WordPress 7.0:  `wp_strip_custom_css_from_blocks`,
		 * `wp_filter_global_styles_post`, `convert_invalid_entities`, `wp_filter_post_kses`.
		 */
		$hook->callbacks = array();

		$post_id = wp_insert_post( $args, true );

		$hook->callbacks = $callbacks_before;

		if ( is_wp_error( $post_id ) ) {
			// TODO Log.
			throw new \Exception( 'WordPress failed to create a ' . $post_type . ' for the email.' );
		}

		// TODO: save attachments.

		return $this->find_by_post_id( $post_id );
	}

	/**
	 * @param Collection<IMessage> $all_new_account_emails
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function save_all(
		Collection $all_new_account_emails,
		BH_WP_Mailboxes_Settings_Interface $mailboxes,
		Email_Account_Settings_Interface $email_account
	): array {

		return array_map(
			fn( $new_email ) => $this->save_new( $new_email, $mailboxes, $email_account ),
			$all_new_account_emails->all()
		);
	}
}

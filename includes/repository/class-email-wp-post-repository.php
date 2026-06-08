<?php
/**
 * Persists and retrieves BH_Email objects as WordPress CPT posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Repository;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use DateTimeInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Post;

/**
 * WordPress post repository for email CPT records.
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
		?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Save a new email or update an existing one (guid-based deduplication).
	 *
	 * @param BH_Email $email The email to persist.
	 *
	 * @return int The WordPress post ID, or 0 on failure.
	 */
	public function save( BH_Email $email ): int {

		$guid        = $this->guid_for( $email );
		$existing_id = $this->find_post_id_by_guid( $guid );

		$meta                       = $email->get_meta_data();
		$meta['email_id']           = $email->get_email_id();
		$meta['from_email']         = $email->get_from_email();
		$meta['from_name']          = $email->get_from_name();
		$meta['headers']            = array_keys( $email->get_headers() );
		$meta['bh_email_body_html'] = $email->get_body_html();
		if ( ! is_null( $email->get_is_read() ) ) {
			$meta['bh_email_is_read'] = $email->get_is_read() ? '1' : '0';
		}

		foreach ( $email->get_headers() as $name => $value ) {
			$meta[ $name ] = $value;
		}

		$args = array(
			'post_title'    => $email->get_subject(),
			'post_name'     => sanitize_title( $email->get_subject() ),
			'post_content'  => $email->get_body_plain_text(),
			'post_date'     => $this->resolve_post_date( $email ),
			'post_status'   => $email->get_post_status(),
			'post_type'     => $email->get_post_type(),
			'post_category' => array( $email->get_account_category_id() ),
			'meta_input'    => $meta,
			'guid'          => $guid,
		);

		if ( ! is_null( $existing_id ) ) {
			$args['ID'] = $existing_id;
		}

		$post_id = wp_insert_post( $args );

		if ( 0 === $post_id ) {
			$this->logger->error(
				'Failed to save email.',
				array( 'email_id' => $email->get_email_id() )
			);
			return 0;
		}

		$this->logger->debug(
			is_null( $existing_id ) ? 'Saved new email.' : 'Updated existing email.',
			array(
				'post_id'  => $post_id,
				'email_id' => $email->get_email_id(),
			)
		);

		return $post_id;
	}

	/**
	 * Hydrate a BH_Email from a WordPress post ID.
	 *
	 * @param int $post_id The WordPress post ID.
	 *
	 * @return BH_Email
	 */
	public function find_by_post_id( int $post_id ): BH_Email {
		$cpt = get_post( $post_id );
		if ( ! ( $cpt instanceof WP_Post ) ) {
			throw new \InvalidArgumentException( "No post found with ID {$post_id}." );
		}
		return $this->hydrate_from_wp_post( $cpt );
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
				$emails[] = $this->hydrate_from_wp_post( $post );
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
	 * Build a BH_Email from a WP_Post and its meta.
	 *
	 * @param WP_Post $cpt The email CPT post.
	 *
	 * @return BH_Email
	 */
	protected function hydrate_from_wp_post( WP_Post $cpt ): BH_Email {

		$post_id = $cpt->ID;

		$email_id   = get_post_meta( $post_id, 'email_id', true );
		$from_email = get_post_meta( $post_id, 'from_email', true );
		$from_name  = get_post_meta( $post_id, 'from_name', true );

		$headers      = array();
		$header_names = get_post_meta( $post_id, 'headers', true );
		if ( is_array( $header_names ) ) {
			foreach ( $header_names as $header_name ) {
				$header_value = get_post_meta( $post_id, $header_name, true );
				if ( ! empty( $header_value ) ) {
					$headers[ $header_name ] = $header_value;
				}
			}
		}

		$body_html   = get_post_meta( $post_id, 'bh_email_body_html', true );
		$is_read_raw = get_post_meta( $post_id, 'bh_email_is_read', true );
		$is_read     = '' !== $is_read_raw ? (bool) $is_read_raw : null;

		return new BH_Email(
			post_type:           $cpt->post_type,
			account_category_id: 0,
			email_id:            is_string( $email_id ) ? $email_id : '',
			subject:             $cpt->post_title,
			from_email:          is_string( $from_email ) ? $from_email : '',
			from_name:           is_string( $from_name ) && '' !== $from_name ? $from_name : null,
			body_plain_text:     $cpt->post_content,
			body_html:           is_string( $body_html ) ? $body_html : '',
			headers:             $headers,
			post_id:             $post_id,
			post_status:         $cpt->post_status,
			is_read:             $is_read,
		);
	}

	/**
	 * Build the WordPress guid for deduplication.
	 *
	 * @param BH_Email $email The email to build a guid for.
	 *
	 * @return string
	 */
	protected function guid_for( BH_Email $email ): string {
		$site_url = get_site_url();
		return "{$site_url}|" . sanitize_key( $email->get_email_id() );
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
	 * Determine the post_date to use when inserting the email CPT record.
	 *
	 * @param BH_Email $email The email being saved.
	 *
	 * @return string MySQL datetime string (UTC).
	 */
	protected function resolve_post_date( BH_Email $email ): string {

		if ( ! is_null( $email->get_received_at() ) ) {
			return gmdate( 'Y-m-d H:i:s', $email->get_received_at()->getTimestamp() );
		}

		$date_header = $email->get_headers()['Date'] ?? null;
		if ( ! is_null( $date_header ) ) {
			$parsed = date_create( $date_header );
			if ( false !== $parsed ) {
				return gmdate( 'Y-m-d H:i:s', $parsed->getTimestamp() );
			}
		}

		return gmdate( 'Y-m-d H:i:s' );
	}
}

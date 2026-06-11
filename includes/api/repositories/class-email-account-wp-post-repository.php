<?php
/**
 * WordPress post repository for email account CPT records.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\BH_Email_Account_Query;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Post;

/**
 * Persists and retrieves BH_Email_Account objects as WordPress CPT posts.
 */
class Email_Account_WP_Post_Repository extends WP_Post_Repository_Abstract {
	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param string                   $post_type               CPT slug.
	 * @param BH_Email_Account_Factory $bh_email_account_factory Factory to hydrate WP_Post → BH_Email_Account.
	 * @param LoggerInterface          $logger                  PSR-3 logger.
	 */
	public function __construct(
		protected string $post_type,
		protected BH_Email_Account_Factory $bh_email_account_factory,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Saves a new email account to the database.
	 *
	 * @param string  $email_address Email address for id and display (credentials are separate).
	 * @param string  $display_name Friendly display name.
	 * @param string  $provider_type_class The API the account uses.
	 * @param ?string $from_address_regex_filter Only save emails whose from address matches this regex.
	 * @param ?string $body_identifier_regex_filter Only save emails whose body matches this regex.
	 * @param ?string $after_download_email_action Delete or mark read or do nothing after download (if at all possible).
	 * @param ?int    $delete_emails_after_n_days Delete locally stored emails after n days.
	 *
	 * @throws \Exception When wp_insert_post fails.
	 */
	public function save_new(
		string $email_address,
		string $display_name,
		string $provider_type_class,
		?string $from_address_regex_filter,
		?string $body_identifier_regex_filter,
		?string $after_download_email_action,
		?int $delete_emails_after_n_days,
	): BH_Email_Account {

		$query = new BH_Email_Account_Query(
			post_type: $this->post_type,
			email_address: $email_address,
			display_name: $display_name,
			provider_type_class: $provider_type_class,
			from_address_regex_filter: $from_address_regex_filter,
			body_identifier_regex_filter: $body_identifier_regex_filter,
			after_download_email_action: $after_download_email_action,
			delete_emails_after_n_days: $delete_emails_after_n_days,
		);

		$post_id = $this->insert( $query );

		return $this->find_by_post_id( $post_id );
	}

	/**
	 * Returns a BH_Email_Account by WordPress post ID.
	 *
	 * @param int $post_id The WordPress post ID.
	 * @throws InvalidArgumentException When no post is found with the given ID.
	 */
	public function find_by_post_id( int $post_id ): BH_Email_Account {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer, safe to include in exception.
			throw new InvalidArgumentException( "No post found with ID {$post_id}." );
		}
		return $this->bh_email_account_factory->from_wp_post( $post );
	}

	/**
	 * Returns all email accounts, optionally filtered by status.
	 *
	 * @param string $status Post status to filter by, or 'all' for no filter.
	 *
	 * @return BH_Email_Account[]
	 */
	public function get_all(
		string $status = 'all' // TODO: enum active|inactive|all.
	): array {

		$post_type = $this->post_type;

		$args = new BH_Email_Account_Query(
			post_type: $post_type,
			status: $status
		);

		return $this->run_query(
			$args,
			fn( string $key ): bool => in_array( $key, array( 'post_type', 'post_status' ), true ),
		);
	}


	/**
	 * Queries the repository and returns matching email accounts.
	 *
	 * @param string  $status        Post status to filter by, or 'all'.
	 * @param ?string $email_address Email address to filter by.
	 *
	 * @return array<BH_Email_Account>
	 */
	public function query(
		string $status = 'all',
		?string $email_address = null,
	): array {
		return $this->run_query(
			new BH_Email_Account_Query(
				post_type: $this->post_type,
				status: $status,
				email_address: $email_address,
			)
		);
	}

	/**
	 * Executes a query and returns hydrated BH_Email_Account objects.
	 *
	 * @param BH_Email_Account_Query $query  Query object describing the criteria.
	 * @param ?callable              $filter Optional key-filter applied to the query array before execution.
	 *
	 * @return BH_Email_Account[]
	 */
	protected function run_query( BH_Email_Account_Query $query, ?callable $filter = null ): array {
		$query_args = $query->to_query_array();

		if ( $filter ) {
			$query_args = array_filter(
				$query_args,
				$filter,
				ARRAY_FILTER_USE_KEY,
			);
		}

		$wp_query = new \WP_Query( $query_args );
		/** @var WP_Post[] $posts */
		$posts = $wp_query->posts;

		return array_map(
			$this->bh_email_account_factory->from_wp_post( ... ),
			$posts
		);
	}
}

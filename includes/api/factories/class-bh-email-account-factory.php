<?php
/**
 * Factory for creating BH_Email instances from WordPress posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Factories;

use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\API\Queries\BH_Email_Account_Query;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use DateTime;
use DateTimeInterface;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_Post;

/**
 * Factory for BH_Email_Account objects.
 */
class BH_Email_Account_Factory {
	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Hydrates a BH_Email from a WP_Post.
	 *
	 * @param WP_Post $post The WordPress post to hydrate from.
	 * @throws Exception On type error using db data to instantiate object.
	 */
	public function from_wp_post( WP_Post $post ): BH_Email_Account {

		$args = $this->get_array_from_post_meta( $post );

		return new BH_Email_Account( ...$args );
	}

	/**
	 * Fetch each `get_post_meta()` value and check and sanitize its type.
	 *
	 * @param WP_Post $post The wp_post row.
	 *
	 * @return array{post_id:int<1, max>, post_type:string, local_status:string, after_download_remote_email_action:string|null, body_identifier_regex_filter:string|null, delete_local_emails_after_n_days:int|null, total_emails_downloaded_count:int, total_emails_saved_count:int, display_name:string, email_address:string, from_address_regex_filter:string|null, last_checked_time:DateTimeInterface|null, last_failed_login_time:DateTimeInterface|null, last_successful_login_time:DateTimeInterface|null, connection_type_class:class-string<Email_Connection_Interface>} $args
	 * @throws Exception When an expected value is missing or the incorrect type.
	 */
	protected function get_array_from_post_meta( WP_Post $post ): array {
		$args = array(
			'post_id'                            => $post->ID,
			'post_type'                          => $post->post_type,
			'local_status'                       => $post->post_status,
			'from_address_regex_filter'          => null,
			'body_identifier_regex_filter'       => null,
			'after_download_remote_email_action' => null,
			'delete_local_emails_after_n_days'   => null,
			'total_emails_downloaded_count'      => 0,
			'total_emails_saved_count'           => 0,
			'last_checked_time'                  => null,
			'last_successful_login_time'         => null,
			'last_failed_login_time'             => null,
		);

		/**
		 * TODO: There might be a better way: use a static method for meta keys and use reflection for validation/sanitization.
		 *
		 * @see BH_Email_Account_Query::get_meta_input()
		 */
		$meta_keys = array(
			'connection_type_class',
			'email_address',
			'display_name',
			'from_address_regex_filter',
			'body_identifier_regex_filter',
			'after_download_remote_email_action',
			'delete_local_emails_after_n_days',
			'total_emails_downloaded_count',
			'total_emails_saved_count',
			'last_checked_time',
			'last_successful_login_time',
			'last_failed_login_time',
		);

		$required_keys = array(
			'connection_type_class',
			'email_address',
			'display_name',
		);

		$string_keys = array(
			'post_type',
			'local_status',
			'connection_type_class',
			'email_address',
			'display_name',
			'from_address_regex_filter',
			'body_identifier_regex_filter',
			'after_download_remote_email_action',
		);

		$int_keys = array(
			'post_id',
			'delete_local_emails_after_n_days',
		);

		// Lifetime counters: absent meta (accounts created before the counters existed) reads as zero.
		$count_keys = array(
			'total_emails_downloaded_count',
			'total_emails_saved_count',
		);

		$datetime_keys = array(
			'last_checked_time',
			'last_successful_login_time',
			'last_failed_login_time',
		);

		foreach ( $meta_keys as $meta_key ) {
			$args[ $meta_key ] = get_post_meta( $post->ID, $meta_key, true ) ?: null;
		}

		$this->backfill_identity_meta( $post, $args );

		$errors = array();

		foreach ( $string_keys as $string_key ) {
			// TODO: This is inadequate to sanitize.
			if ( isset( $args[ $string_key ] ) && ! is_string( $args[ $string_key ] ) ) {
				$this->logger->warning(
					'Unexpected value for {string_key}: {type} {value}.',
					array(
						'string_key' => $string_key,
						'type'       => get_debug_type( $args[ $string_key ] ),
						'value'      => $args[ $string_key ],
					)
				);
				unset( $args[ $string_key ] );
			}
		}

		// A value that should be a number but is not is logged, replaced in the database (so the warning
		// does not repeat on every load) and treated as unset, rather than making the account unusable.
		foreach ( $int_keys as $int_key ) {
			if ( is_null( $args[ $int_key ] ) ) {
				continue;
			}
			if ( ! is_numeric( $args[ $int_key ] ) ) {
				$this->replace_unusable_meta( $post, $int_key, $args[ $int_key ], null );
				$args[ $int_key ] = null;
				continue;
			}
			$args[ $int_key ] = (int) $args[ $int_key ] ?: null;
		}

		// Lifetime counters are never negative; anything else stored there is replaced with zero.
		foreach ( $count_keys as $count_key ) {
			if ( is_null( $args[ $count_key ] ) ) {
				$args[ $count_key ] = 0;
				continue;
			}
			if ( ! is_numeric( $args[ $count_key ] ) || (int) $args[ $count_key ] < 0 ) {
				$this->replace_unusable_meta( $post, $count_key, $args[ $count_key ], 0 );
				$args[ $count_key ] = 0;
				continue;
			}
			$args[ $count_key ] = (int) $args[ $count_key ];
		}

		// A timestamp that does not parse is likewise logged, removed and treated as unset.
		foreach ( $datetime_keys as $datetime_key ) {
			if ( is_null( $args[ $datetime_key ] ) ) {
				continue;
			}
			$raw = is_string( $args[ $datetime_key ] ) ? $args[ $datetime_key ] : '';
			try {
				$parsed = DateTime::createFromFormat( DateTimeInterface::ATOM, $raw );
			} catch ( Throwable $throwable ) {
				$parsed = false;
			}
			if ( false === $parsed ) {
				$this->replace_unusable_meta( $post, $datetime_key, $args[ $datetime_key ], null );
				$parsed = null;
			}
			$args[ $datetime_key ] = $parsed;
		}

		foreach ( $required_keys as $required_key ) {
			if ( empty( $args[ $required_key ] ) ) {
				$errors[ $required_key ] = 'Required key is missing or empty.';
			}
		}

		if ( ! empty( $errors ) ) {
			throw new Exception(
				sprintf(
					'Param error hydrating BH_Email_Account from WP_Post ID %d: %s. Try: wp post meta get %d %s / wp post meta list %d',
					intval( $post->ID ),
					implode( ', ', array_map( 'esc_html', array_keys( $errors ) ) ),
					intval( $post->ID ),
					esc_html( (string) array_key_first( $errors ) ),
					intval( $post->ID ),
				)
			);
		}

		/**
		 * It's unhappy with post_id. TODO.
		 *
		 * @phpstan-ignore return.type
		 */
		return $args;
	}

	/**
	 * Log a stored meta value that cannot be used, and overwrite it (or delete it, for null) so the
	 * account hydrates cleanly, and silently, from the next load on.
	 *
	 * @param WP_Post  $post        The account post.
	 * @param string   $meta_key    The meta key.
	 * @param mixed    $value       The unusable stored value.
	 * @param int|null $replacement What to store instead; null deletes the meta.
	 */
	protected function replace_unusable_meta( WP_Post $post, string $meta_key, mixed $value, ?int $replacement ): void {
		$printable = is_scalar( $value ) ? (string) $value : get_debug_type( $value );

		$this->logger->warning(
			'Discarding unusable ' . $meta_key . ' "' . $printable . '" on email account post ' . $post->ID
			. ( is_null( $replacement ) ? '; the meta has been deleted.' : '; replaced with ' . $replacement . '.' ),
			array(
				'key'     => $meta_key,
				'value'   => $value,
				'post_id' => $post->ID,
			)
		);

		if ( is_null( $replacement ) ) {
			delete_post_meta( $post->ID, $meta_key );
		} else {
			update_post_meta( $post->ID, $meta_key, $replacement );
		}
	}

	/**
	 * Default a missing display name to the email address, and write it back so the account is whole
	 * from then on. A missing email address cannot be defaulted here: the slug is a lossy encoding of
	 * it ({@see BH_Email_Account_Query::get_wp_post_fields()}), but
	 * {@see \BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository::find_by_email_address()}
	 * knows the address it looked up and heals that meta before hydrating.
	 *
	 * @param WP_Post              $post The account post.
	 * @param array<string, mixed> $args The hydration args, updated in place.
	 */
	protected function backfill_identity_meta( WP_Post $post, array &$args ): void {
		if ( ! empty( $args['display_name'] ) || empty( $args['email_address'] ) || ! is_string( $args['email_address'] ) ) {
			return;
		}

		$args['display_name'] = $args['email_address'];
		update_post_meta( $post->ID, 'display_name', $args['email_address'] );
		$this->logger->warning(
			'Email account post ' . $post->ID . ' had no display_name meta; defaulted to its email address.',
			array( 'post_id' => $post->ID )
		);
	}
}

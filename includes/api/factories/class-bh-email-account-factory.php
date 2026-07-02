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
	 * @return array{post_id:int<1, max>, post_type:string, local_status:string, after_download_remote_email_action:string|null, body_identifier_regex_filter:string|null, delete_local_emails_after_n_days:int|null, display_name:string, email_address:string, from_address_regex_filter:string|null, last_checked_time:DateTimeInterface|null, last_failed_login_time:DateTimeInterface|null, last_successful_login_time:DateTimeInterface|null, connection_type_class:class-string<Email_Connection_Interface>} $args
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

		$datetime_keys = array(
			'last_checked_time',
			'last_successful_login_time',
			'last_failed_login_time',
		);

		foreach ( $meta_keys as $meta_key ) {
			$args[ $meta_key ] = get_post_meta( $post->ID, $meta_key, true ) ?: null;
		}

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

		foreach ( $int_keys as $int_key ) {
			$args[ $int_key ] = (int) $args[ $int_key ] ?: null;
		}

		foreach ( $datetime_keys as $datetime_key ) {
			if ( ! is_null( $args[ $datetime_key ] ) ) {
				try {
					$args[ $datetime_key ] = DateTime::createFromFormat( DateTime::ATOM, $args[ $datetime_key ] );
				} catch ( Throwable $throwable ) {
					$errors[ $datetime_key ] = $throwable;
				}
				if ( false === $args[ $datetime_key ] ) {
					$errors[ $datetime_key ] = 'Failed to parse date/time: ' . $args[ $datetime_key ];
				}
			}
		}

		foreach ( $required_keys as $required_key ) {
			if ( empty( $args[ $required_key ] ) ) {
				$errors[ $required_key ] = 'Required key is missing or empty.';
			}
		}

		if ( ! empty( $errors ) ) {
			throw new Exception(
				sprintf(
					'Param error hydrating BH_Email_Account from WP_Post ID %d: %s',
					intval( $post->ID ),
					implode( ', ', array_map( 'esc_html', array_keys( $errors ) ) )
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
}

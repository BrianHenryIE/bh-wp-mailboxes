<?php
/**
 * Factory for creating BH_Email instances from WordPress posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Factories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use DateTime;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TypeError;
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

		// TODO: NB: Sanitize.

		$provider_type_class              = get_post_meta( $post->ID, 'provider_type_class', true ) ?: null;
		$email_address                    = get_post_meta( $post->ID, 'email_address', true ) ?: null;
		$display_name                     = get_post_meta( $post->ID, 'display_name', true ) ?: null;
		$from_address_regex_filter        = get_post_meta( $post->ID, 'from_address_regex_filter', true ) ?: null;
		$body_identifier_regex_filter     = get_post_meta( $post->ID, 'body_identifier_regex_filter', true ) ?: null;
		$after_download_email_action      = get_post_meta( $post->ID, 'after_download_email_action', true ) ?: null;
		$delete_local_emails_after_n_days = get_post_meta( $post->ID, 'delete_local_emails_after_n_days', true ) ?: null;
		$last_successful_login_raw        = get_post_meta( $post->ID, 'last_successful_login_time', true );
		$last_successful_login_time       = $last_successful_login_raw ? DateTime::createFromFormat( DateTime::ATOM, $last_successful_login_raw ) ?: null : null;
		$last_failed_login_raw            = get_post_meta( $post->ID, 'last_failed_login_time', true );
		$last_failed_login_time           = $last_failed_login_raw ? DateTime::createFromFormat( DateTime::ATOM, $last_failed_login_raw ) ?: null : null;

		try {
			return new BH_Email_Account(
				$post->ID,
				$post->post_type,
				$post->post_status,
				$provider_type_class,
				$email_address,
				$display_name,
				$from_address_regex_filter,
				$body_identifier_regex_filter,
				$after_download_email_action,
				$delete_local_emails_after_n_days,
				$last_successful_login_time,
				$last_failed_login_time,
			);
		} catch ( TypeError $type_error ) {

			// TODO: Figure out what field is missing.
			throw new Exception(
				'Invalid saved BH_Email_Account object',
				esc_html( $type_error->getCode() ),
				esc_html( $type_error )
			);
		}
	}
}

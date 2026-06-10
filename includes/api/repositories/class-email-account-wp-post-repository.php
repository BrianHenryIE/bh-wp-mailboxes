<?php
/**
 * WordPress post repository for email account CPT records.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

/**
 * Persists and retrieves BH_Email_Account objects as WordPress CPT posts.
 */
class Email_Account_WP_Post_Repository extends WP_Post_Repository_Abstract {

	/**
	 * Saves a new email account to the database.
	 *
	 * @param Email_Account_Settings_Interface $email_account_settings The email account settings to save.
	 */
	public function save_new( Email_Account_Settings_Interface $email_account_settings ): Saved_Post {
		throw new \RuntimeException( 'Not implemented.' );
	}

	/**
	 * Returns a BH_Email_Account by WordPress post ID.
	 *
	 * @param int $post_id The WordPress post ID.
	 */
	public function get_by_wp_post_id( int $post_id ): BH_Email_Account {
		throw new \RuntimeException( 'Not implemented.' );
	}

	/**
	 * Queries the repository and returns matching email accounts.
	 *
	 * @return array<BH_Email_Account>
	 */
	public function query(): array {
		throw new \RuntimeException( 'Not implemented.' );
	}
}

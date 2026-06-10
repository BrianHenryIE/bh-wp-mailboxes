<?php
/**
 * A mailbox is a collection of email_accounts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Saved_Post;

/**
 * Represents a saved email account backed by a WordPress post.
 */
class BH_Email_Account implements Saved_Post {

	/**
	 * The mailboxes plugin settings.
	 *
	 * @var BH_WP_Mailboxes_Settings_Interface
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * The WordPress post ID for this email account.
	 *
	 * @var int
	 */
	protected int $post_id;

	/**
	 * Returns the WordPress post ID.
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}

	/**
	 * Returns the time this mailbox was last checked.
	 */
	public function get_last_checked_time(): \DateTimeInterface {
		throw new \RuntimeException( 'Not implemented.' );
	}
}

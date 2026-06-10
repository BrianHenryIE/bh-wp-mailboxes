<?php
/**
 * A mailbox is a collection of email_accounts
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Saved_Post;

class BH_Email_Account implements Saved_Post {

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	protected int $post_id;

	public function get_post_id(): int {
		return $this->post_id;
	}

	public function get_last_checked_time(): \DateTimeInterface {
	}
}

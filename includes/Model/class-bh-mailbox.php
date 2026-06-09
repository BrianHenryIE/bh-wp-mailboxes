<?php
/**
 * A mailbox is a collection of email_accounts
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\Repository\Saved_Post;

class BH_Mailbox implements Saved_Post {

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	protected int $post_id;

	public function get_post_id(): int {
		return $this->post_id;
	}
}

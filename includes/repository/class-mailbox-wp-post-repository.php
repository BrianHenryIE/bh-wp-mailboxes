<?php

namespace BrianHenryIE\WP_Mailboxes\Repository;

use BrianHenryIE\WP_Mailboxes\BH_Mailbox;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

class Mailbox_WP_Post_Repository extends WP_Post_Repository_Abstract {

	public function save_new( Email_Account_Settings_Interface $mailbox_settings ): Saved_Post {
	}
	public function get_by_wp_post_id( int $post_id ): BH_Mailbox {
	}
	public function query( WP_Post_Query_Abstract $query ): array {
	}
}

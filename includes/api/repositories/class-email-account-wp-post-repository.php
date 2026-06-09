<?php

namespace BrianHenryIE\WP_Mailboxes\API\Repositories;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

class Email_Account_WP_Post_Repository extends WP_Post_Repository_Abstract {

	public function save_new( Email_Account_Settings_Interface $mailbox_settings ): Saved_Post {
	}
	public function get_by_wp_post_id( int $post_id ): BH_Email_Account {
	}
	public function query( WP_Post_Query_Abstract $query ): array {
	}
}

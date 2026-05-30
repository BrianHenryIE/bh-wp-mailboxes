<?php
/**
 *
 */

namespace BrianHenryIE\WP_Mailboxes;

use DateTime;
use WP_Post;
use ZBateson\MailMimeParser\IMessage;

class BH_Email_Factory {

	public function save_post( WP_Post $wp_post ) {
		$bh_email = BH_Email::create_from_cpt( $wp_post );
	}

	public function save_new( array $email ) {
	}
}

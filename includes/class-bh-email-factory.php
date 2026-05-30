<?php
/**
 *
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use WP_Post;

class BH_Email_Factory {

	public function save_post( WP_Post $wp_post ): BH_Email {
		$bh_email = BH_Email::create_from_cpt( $wp_post );
	}

	public function save_new( array $email ) {
	}
}

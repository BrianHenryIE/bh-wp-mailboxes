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

	public function get_downloaded_emails( array $args ): array {

		$args['post_parent'] = $this->post_id;
		$args['post_type']   = $this->settings->get_cpt_underscored_20();

		// wp_parse_args( defaults )

		$wp_posts = get_posts( $args );

		return array_map( array( BH_Email::class, 'create_from_cpt' ), $wp_posts );
	}

	public function get_post_id(): int {
		return $this->post_id;
	}
}

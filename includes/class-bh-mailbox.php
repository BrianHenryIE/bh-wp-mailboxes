<?php
/**
 * A mailbox is a server connection and settings.
 */

namespace BrianHenryIE\WP_Mailboxes;

class BH_Mailbox {

	protected \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface $settings;

	protected int $post_id;

	public function get_downloaded_emails( array $args ): array {

		$args['post_parent'] = $this->post_id;
		$args['post_type']   = $this->settings->get_cpt_underscored_20();

		// wp_parse_args( defaults )

		$wp_posts = get_posts( $args );

		$bh_emails = array_map( array( \BrianHenryIE\WP_Mailboxes\Model\BH_Email::class, 'create_from_cpt' ), $wp_posts );

		return $bh_emails;
	}
}

<?php
/**
 * WPUnit tests for API.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\API
 */
class API_WPUnit_Test extends WPUnit_Testcase {

	// -------------------------------------------------------------------------
	// Order notes / log comments
	// -------------------------------------------------------------------------

	/**
	 * Requirement 7: insert_email_log_note creates a WP comment with comment_type 'bh_email_log'.
	 *
	 * This confirms that status-change logs are stored in a way that lets them be
	 * rendered like WooCommerce order notes (same pattern: custom comment_type on the post).
	 *
	 * @covers ::insert_email_log_note
	 */
	public function test_insert_email_log_note_creates_comment_with_bh_email_log_type(): void {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => fn() => 'bh_wp_mailboxes_cpt',
			)
		);

		$api = new API( $settings, null, $this->logger );

		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$api->insert_email_log_note( $post_id, 'Status changed from "bh_email_new" to "bh_email_processed".' );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'bh_email_log',
			)
		);

		$this->assertCount( 1, $comments, 'Exactly one bh_email_log comment should exist' );
		$this->assertSame( 'bh_email_log', $comments[0]->comment_type );
		$this->assertStringContainsString( 'bh_email_processed', $comments[0]->comment_content );
	}
}

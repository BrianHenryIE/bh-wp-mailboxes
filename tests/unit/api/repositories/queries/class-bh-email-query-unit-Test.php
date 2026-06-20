<?php
/**
 * Unit tests for BH_Email_Query.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\BH_Email_Query
 */
class BH_Email_Query_Unit_Test extends Unit_Testcase {

	/**
	 * `wp_update_post()` requires `ID`; the `post_id` constructor argument must be emitted as `ID`.
	 *
	 * Regression test: previously `post_id` was accepted but never mapped, so
	 * `Email_WP_Post_Repository::update()` ran `wp_update_post()` without an ID.
	 *
	 * @covers ::__construct
	 * @covers ::get_wp_post_fields
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract::to_wp_post_array
	 */
	public function test_to_wp_post_array_includes_post_id_as_wp_post_id_field(): void {

		$sut = new BH_Email_Query(
			post_type: 'bh_email',
			post_id: 789,
			local_status: 'bh_email_processed',
		);

		$result = $sut->to_wp_post_array();

		$this->assertArrayHasKey( 'ID', $result );
		$this->assertSame( 789, $result['ID'] );
		$this->assertSame( 'bh_email_processed', $result['post_status'] );
	}

	/**
	 * A null post_id (a new email being inserted) must not appear in the query array.
	 *
	 * @covers ::get_wp_post_fields
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract::to_wp_post_array
	 */
	public function test_to_wp_post_array_omits_id_field_when_post_id_is_null(): void {

		$sut = new BH_Email_Query(
			post_type: 'bh_email',
			subject: 'Test subject',
		);

		$result = $sut->to_wp_post_array();

		$this->assertArrayNotHasKey( 'ID', $result );
		$this->assertSame( 'Test subject', $result['post_title'] );
	}
}

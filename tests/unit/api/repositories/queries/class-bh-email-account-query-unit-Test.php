<?php
/**
 * Unit tests for BH_Email_Account_Query.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\BH_Email_Account_Query
 */
class BH_Email_Account_Query_Unit_Test extends Unit_Testcase {

	/**
	 * `wp_update_post()` silently targets the wrong post (or errors) without `ID`, so the
	 * `post_id` constructor argument must be emitted as `ID` in the query array.
	 *
	 * Regression test: previously `post_id` was accepted but never mapped, so every
	 * `Email_Account_WP_Post_Repository::update()` call ran `wp_update_post()` without an ID.
	 *
	 * @covers ::__construct
	 * @covers ::get_wp_post_fields
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract::__construct
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract::to_wp_post_array
	 */
	public function test_to_wp_post_array_includes_post_id_as_wp_post_id_field(): void {

		$sut = new BH_Email_Account_Query(
			post_type: 'bh_email_account',
			post_id: 123,
			last_checked_time: new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
		);

		$result = $sut->to_wp_post_array();

		$this->assertArrayHasKey( 'ID', $result );
		$this->assertSame( 123, $result['ID'] );
	}

	/**
	 * A null post_id (e.g. when inserting a new account) must not appear in the query array.
	 *
	 * @covers ::get_wp_post_fields
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract::to_wp_post_array
	 */
	public function test_to_wp_post_array_omits_id_field_when_post_id_is_null(): void {

		$sut = new BH_Email_Account_Query(
			post_type: 'bh_email_account',
			status: 'bh_email_ac_active',
		);

		$result = $sut->to_wp_post_array();

		$this->assertArrayNotHasKey( 'ID', $result );
		$this->assertSame( 'bh_email_ac_active', $result['post_status'] );
	}

	/**
	 * DateTimeInterface meta values must be serialized in ATOM format; null meta values dropped.
	 *
	 * @covers ::get_meta_input
	 * @covers \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Post_Query_Abstract::to_wp_post_array
	 */
	public function test_to_wp_post_array_formats_last_failed_login_time_as_atom_and_drops_nulls(): void {

		$last_failed_login_time = new DateTimeImmutable( '2026-06-12T01:02:03+00:00' );

		$sut = new BH_Email_Account_Query(
			post_type: 'bh_email_account',
			post_id: 456,
			last_failed_login_time: $last_failed_login_time,
		);

		$result = $sut->to_wp_post_array();

		$this->assertSame(
			$last_failed_login_time->format( DateTimeInterface::ATOM ),
			$result['meta_input']['last_failed_login_time']
		);
		$this->assertArrayNotHasKey( 'last_checked_time', $result['meta_input'] );
		$this->assertArrayNotHasKey( 'display_name', $result['meta_input'] );
	}
}

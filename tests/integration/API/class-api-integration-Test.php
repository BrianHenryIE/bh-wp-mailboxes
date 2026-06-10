<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @coversNothing
 */
class API_Integration_Test extends \Codeception\TestCase\WPTestCase {

	/**
	 * Test timezone doesn't change after saving and retrieving in wp_options.
	 */
	public function test_timezone(): void {

		$this->markTestSkipped( 'out of date' );

		$api = BH_WP_Mailboxes::instance();

		$account_name = 'support@brianhenryie.com';
		$now_time     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$api->set_last_fetched_time( $account_name, $now_time );

		$result = $api->get_last_fetched_times();

		/** @var \DateTimeInterface $retrieved_time */
		$retrieved_time = $result[ $account_name ];

		$this->assertContains( $retrieved_time->getTimezone()->getName(), array( 'UTC', '+00:00' ) );
	}
}

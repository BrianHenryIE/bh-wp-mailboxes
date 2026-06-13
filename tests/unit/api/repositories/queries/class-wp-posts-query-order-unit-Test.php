<?php
/**
 * Unit tests for WP_Posts_Query_Order::to_query_array().
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Queries;

use BrianHenryIE\WP_Mailboxes\Unit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\API\Repositories\Queries\WP_Posts_Query_Order
 */
class WP_Posts_Query_Order_Unit_Test extends Unit_Testcase {

	/**
	 * Every field maps to its WP_Query arg: count→numberposts, order_by→orderby, order_direction→order.
	 *
	 * @covers ::to_query_array
	 */
	public function test_all_fields_map_to_query_args(): void {
		$order = new WP_Posts_Query_Order( count: 25, order_by: 'date', order_direction: 'DESC' );

		$this->assertSame(
			array(
				'numberposts' => 25,
				'orderby'     => 'date',
				'order'       => 'DESC',
			),
			$order->to_query_array(),
		);
	}

	/**
	 * Unset fields are omitted entirely (no nulls passed to WP_Query).
	 *
	 * @covers ::to_query_array
	 */
	public function test_unset_fields_are_omitted(): void {
		$this->assertSame( array(), ( new WP_Posts_Query_Order() )->to_query_array() );

		$this->assertSame(
			array( 'numberposts' => 10 ),
			( new WP_Posts_Query_Order( count: 10 ) )->to_query_array(),
		);
	}
}

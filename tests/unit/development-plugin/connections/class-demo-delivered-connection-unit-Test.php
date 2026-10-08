<?php
/**
 * Unit tests for the Demo mailbox's receive-only connection.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

use BrianHenryIE\WP_Mailboxes\API\Supports_Fetching;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections\Demo_Delivered_Connection
 */
class Demo_Delivered_Connection_Unit_Test extends Unit_Testcase {

	/**
	 * The connection, for a mailbox with plugin slug `development-plugin` and emails CPT `demo_email`.
	 */
	private function make_sut(): Demo_Delivered_Connection {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'development-plugin' );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'demo_email' );

		return new Demo_Delivered_Connection( $settings );
	}

	/**
	 * Emails are pushed to this account, so it must not offer fetching.
	 *
	 * @covers ::__construct
	 */
	public function test_is_receive_only(): void {
		$this->assertNotInstanceOf( Supports_Fetching::class, $this->make_sut() );
	}

	/**
	 * It has a friendly name, and there is nothing to connect to, so the connection test passes.
	 *
	 * @covers ::get_friendly_name
	 * @covers ::test_connection
	 */
	public function test_friendly_name_and_test_connection(): void {
		$sut = $this->make_sut();

		$this->assertSame( 'Demo (delivered, receive only)', $sut->get_friendly_name() );
		$this->assertTrue( $sut->test_connection() );
	}

	/**
	 * The connection filter is registered with the four arguments `connection()` takes.
	 *
	 * @covers ::register_hooks
	 */
	public function test_register_hooks(): void {
		$sut = $this->make_sut();

		\WP_Mock::expectFilterAdded( 'bh_wp_mailboxes_connection_for_account', array( $sut, 'connection' ), 10, 4 );

		$sut->register_hooks();
	}

	/**
	 * It is returned only for the Demo mailbox's accounts configured to use it.
	 *
	 * @covers ::connection
	 */
	public function test_connection_is_returned_only_for_its_own_accounts(): void {
		$sut = $this->make_sut();

		$own_account   = BH_Email_Account_Fixture::make( connection_type_class: Demo_Delivered_Connection::class );
		$other_account = BH_Email_Account_Fixture::make( connection_type_class: Demo_Fetching_Connection::class );

		$this->assertSame( $sut, $sut->connection( null, 'development-plugin', 'demo_email', $own_account ) );

		$this->assertNull( $sut->connection( null, 'development-plugin', 'demo_email', $other_account ), 'Another connection class.' );
		$this->assertNull( $sut->connection( null, 'development-plugin', 'fixtures_email', $own_account ), 'Another mailbox.' );
		$this->assertNull( $sut->connection( null, 'another-plugin', 'demo_email', $own_account ), 'Another plugin.' );

		$earlier = new \stdClass();
		$this->assertSame( $earlier, $sut->connection( $earlier, 'development-plugin', 'fixtures_email', $own_account ), 'An earlier filter result is passed through.' );
	}
}

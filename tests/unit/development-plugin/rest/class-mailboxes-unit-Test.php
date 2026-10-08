<?php
/**
 * Unit tests for the development plugin's simulated WordPress Playground switch.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest;

use BrianHenryIE\WP_Mailboxes\Admin\Email_Account_Modal;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Mailbox_Settings;
use Mockery;
use WP_REST_Request;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes_Development_Plugin\Rest\Mailboxes
 */
class Mailboxes_Unit_Test extends Unit_Testcase {

	/**
	 * The dev REST class for the E2E mailbox.
	 */
	private function make_sut(): Mailboxes {
		return new Mailboxes( new Mailbox_Settings( 'development-plugin', 'E2E Email', 'E2E Accounts', 'bh-wp-mailboxes-dev' ) );
	}

	/**
	 * The simulation is hooked to the library's Playground filter.
	 *
	 * @covers ::register_hooks
	 */
	public function test_register_hooks_adds_the_playground_filter(): void {
		\WP_Mock::userFunction( 'add_action' );
		\WP_Mock::expectFilterAdded( Email_Account_Modal::IS_WORDPRESS_PLAYGROUND_FILTER, \WP_Mock\Functions::type( 'callable' ) );

		$this->make_sut()->register_hooks();
	}

	/**
	 * While the option is set the site is treated as Playground; otherwise detection decides.
	 *
	 * @covers ::simulate_playground
	 */
	public function test_simulate_playground(): void {
		$sut = $this->make_sut();

		\WP_Mock::userFunction( 'get_option' )->with( Mailboxes::SIMULATE_PLAYGROUND_OPTION, false )->andReturn( false, true );

		$this->assertFalse( $sut->simulate_playground( false ), 'Not simulated, not detected.' );
		$this->assertTrue( $sut->simulate_playground( false ), 'Simulated.' );
		$this->assertTrue( $sut->simulate_playground( true ), 'Detected.' );
	}

	/**
	 * The route sets the option by default and deletes it with `enabled: false`.
	 *
	 * @covers ::set_simulate_playground
	 */
	public function test_set_simulate_playground(): void {
		$sut = $this->make_sut();

		$enable = Mockery::mock( WP_REST_Request::class );
		$enable->allows( 'get_param' )->with( 'enabled' )->andReturnNull();
		\WP_Mock::userFunction( 'update_option' )->once()->with( Mailboxes::SIMULATE_PLAYGROUND_OPTION, true, false );

		$this->assertSame( array( 'enabled' => true ), $sut->set_simulate_playground( $enable )->get_data() );

		$disable = Mockery::mock( WP_REST_Request::class );
		$disable->allows( 'get_param' )->with( 'enabled' )->andReturnFalse();
		\WP_Mock::userFunction( 'delete_option' )->once()->with( Mailboxes::SIMULATE_PLAYGROUND_OPTION );

		$this->assertSame( array( 'enabled' => false ), $sut->set_simulate_playground( $disable )->get_data() );
	}
}

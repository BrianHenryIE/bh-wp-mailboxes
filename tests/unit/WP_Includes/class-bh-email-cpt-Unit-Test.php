<?php
/**
 * Tests for BH_Email_CPT.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;
use WP_Error;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT
 */
class BH_Email_CPT_Unit_Test extends Unit_Testcase {

	/**
	 * Build and return a BH_Email_CPT instance with stubbed settings.
	 *
	 * @param string $post_type    The CPT slug to configure.
	 * @param string $friendly_name The human-readable CPT name.
	 */
	protected function make_sut( string $post_type = 'test_email', string $friendly_name = 'Test Email' ): BH_Email_CPT {

		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_emails_cpt_underscored_20' => $post_type,
				'get_emails_cpt_friendly_name'  => $friendly_name,
			)
		);

		return new BH_Email_CPT( $settings, $this->logger );
	}

	/**
	 * Register_cpt() must call register_post_type with the configured post type key.
	 *
	 * @covers ::register_cpt
	 */
	public function test_register_cpt_calls_register_post_type_with_correct_post_type(): void {

		$sut = $this->make_sut( post_type: 'my_email_cpt' );

		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'sanitize_title' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		$post_type_stub = Mockery::mock( \WP_Post_Type::class );

		\WP_Mock::userFunction(
			'register_post_type',
			array(
				'times' => 1,
				'args'  => array( 'my_email_cpt', \WP_Mock\Functions::type( 'array' ) ),
				'return' => $post_type_stub,
			)
		);

		$sut->register_cpt();
	}

	/**
	 * When register_post_type returns a WP_Error, an error must be logged.
	 *
	 * @covers ::register_cpt
	 * @covers ::__construct
	 */
	public function test_register_cpt_logs_error_when_registration_fails(): void {

		$sut = $this->make_sut();

		$wp_error = Mockery::mock( WP_Error::class );
		$wp_error->shouldReceive( 'get_error_message' )->andReturn( 'Registration failed.' );

		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'sanitize_title' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'register_post_type' )->andReturn( $wp_error );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );

		$sut->register_cpt();

		$this->assertTrue( $this->logger->hasError( 'Registration failed.' ) );
	}

	/**
	 * Register_post_statuses() must register all three email post statuses.
	 *
	 * @covers ::register_post_statuses
	 */
	public function test_register_post_statuses_registers_three_statuses(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( '_x' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( array() );

		\WP_Mock::userFunction( 'register_post_status', array( 'times' => 3 ) );

		$sut->register_post_statuses();
	}

	/**
	 * Register_post_statuses() must include the bh_email_new status.
	 *
	 * @covers ::register_post_statuses
	 */
	public function test_register_post_statuses_includes_bh_email_new(): void {

		$sut = $this->make_sut();

		\WP_Mock::userFunction( '_x' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( array() );

		\WP_Mock::userFunction(
			'register_post_status',
			array(
				'times' => 1,
				'args'  => array( 'bh_email_new', \WP_Mock\Functions::type( 'array' ) ),
			)
		);

		\WP_Mock::userFunction( 'register_post_status' );

		$sut->register_post_statuses();
	}
}

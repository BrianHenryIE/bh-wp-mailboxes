<?php

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Codeception\Stub\Expected;

/**
 * @coversDefaultClass  \BrianHenryIE\WP_Mailboxes\Admin\Ajax
 */
class AJAX_Unit_Test extends Unit_Testcase {

	protected function setup(): void {
		parent::setup();
		\WP_Mock::setUp();
	}

	protected function tearDown(): void {
		parent::tearDown();
		\WP_Mock::tearDown();
	}

	/**
	 * @covers ::check_email
	 * @covers ::__construct
	 */
	public function test_check_email_happy_path(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => Expected::once(
					function () {
						return 'mailboxes_cpt'; }
				),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::once(
					function () {
						return array(
							'success' => true,
						);
					}
				),
			)
		);

		$sut = new Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => true,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'args'  => array( \WP_Mock\Functions::type( 'array' ) ),
				'times' => 1,
			)
		);

		$sut->check_email();
	}

	/**
	 * @covers ::check_email
	 */
	public function test_check_email_api_returns_failure(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => Expected::once(
					function () {
						return 'mailboxes_cpt'; }
				),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::once(
					function () {
						return array(
							'success' => false,
						);
					}
				),
			)
		);

		$sut = new Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => true,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'args'  => array( \WP_Mock\Functions::type( 'array' ) ),
				'times' => 1,
			)
		);

		// Act.

		$sut->check_email();
	}


	/**
	 * If the nonce fails, none of the usually expected functions will be called.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_nonce_failure(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => Expected::never(),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::never(),
			)
		);

		$sut = new Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => false,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'times' => 0,
			)
		);

		// Act.

		$sut->check_email();
	}


	/**
	 * If the cpt does not match, none of the usually expected functions will be called.
	 *
	 * @covers ::check_email
	 */
	public function test_check_email_cpt_mismatch(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => Expected::once(
					function () {
						return 'mailboxes_cpt'; }
				),
			)
		);
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::never(),
			)
		);

		$sut = new Ajax( $api, $settings, $logger );

		// Arrange.

		$_POST['_wpnonce']      = '_wpnonce';
		$_POST['mailboxes_cpt'] = 'mailboxes_cpt_is_wrong_for_this_test';

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'return_arg' => true,
			)
		);

		\WP_Mock::userFunction(
			'wp_verify_nonce',
			array(
				'return' => true,
				'times'  => 1,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'times' => 0,
			)
		);

		// Act.

		$sut->check_email();
	}
}

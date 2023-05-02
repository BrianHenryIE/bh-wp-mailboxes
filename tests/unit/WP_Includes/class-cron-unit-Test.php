<?php

namespace BrianHenryIE\BH_WP_Mailboxes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Cron;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;

use Codeception\Stub\Expected;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\Cron
 */
class Cron_Unit_Test extends \Codeception\Test\Unit {

	protected function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	protected function tearDown(): void {
		parent::tearDown();
		\WP_Mock::tearDown();
	}

	/**
	 * @covers ::get_fetch_emails_cron_hook_name
	 * @covers ::__construct
	 */
	public function test_get_fetch_emails_cron_hook_name(): void {
		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_friendly_name' => Expected::once(
					function() {
						return 'Test CPT Name'; }
				),
			)
		);
		$api      = $this->makeEmpty( API_Interface::class );

		$sut = new Cron( $api, $settings, $logger );

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'args'   => array( 'Test CPT Name' ),
				'return' => 'test-cpt-name',
				'times'  => 1,
			)
		);

		$result = $sut->get_fetch_emails_cron_hook_name();

		$this->assertEquals( 'test-cpt-name_fetch_emails_job', $result );
	}


	/**
	 * @covers ::get_delete_local_emails_cron_hook_name
	 * @covers ::__construct
	 */
	public function test_get_delete_local_emails_cron_hook_name(): void {
		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_friendly_name' => Expected::once(
					function() {
						return 'Test CPT Name'; }
				),
			)
		);
		$api      = $this->makeEmpty( API_Interface::class );

		$sut = new Cron( $api, $settings, $logger );

		\WP_Mock::userFunction(
			'sanitize_key',
			array(
				'args'   => array( 'Test CPT Name' ),
				'return' => 'test-cpt-name',
				'times'  => 1,
			)
		);

		$result = $sut->get_delete_local_emails_cron_hook_name();

		$this->assertEquals( 'test-cpt-name_delete_local_emails_job', $result );
	}

	/**
	 * @covers ::background_fetch_emails
	 */
	public function test_background_fetch_emails(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty( BH_WP_Mailboxes_Settings_Interface::class );
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'check_email' => Expected::once(
					function() {
						return array();}
				),
			)
		);

		$sut = new Cron( $api, $settings, $logger );

		$sut->background_fetch_emails();

	}

	/**
	 * @covers ::background_delete_local_emails
	 */
	public function test_background_delete_local_emails(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty( BH_WP_Mailboxes_Settings_Interface::class );
		$api      = $this->makeEmpty(
			API_Interface::class,
			array(
				'background_delete_local_emails' => Expected::once(
					function() {
						return array();}
				),
			)
		);

		$sut = new Cron( $api, $settings, $logger );

		$sut->background_delete_local_emails();
	}
}

<?php

namespace BrianHenryIE\BH_WP_Mailboxes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Mailboxes\WP_Includes\Cron;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;

use Codeception\Stub\Expected;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\Cron
 */
class Cron_Unit_Test extends Unit_Testcase {

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
				'get_emails_cpt_underscored_20' => Expected::once(
					fn() => 'test_cpt_name'
				),
			)
		);
		$api      = $this->makeEmpty( API_Interface::class );

		$sut = new Cron( $api, $settings, $logger );

		$result = $sut->get_fetch_emails_cron_hook_name();

		$this->assertEquals( 'test_cpt_name_fetch_emails_job', $result );
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
				'get_emails_cpt_underscored_20' => Expected::once(
					fn() => 'test_cpt_name'
				),
			)
		);
		$api      = $this->makeEmpty( API_Interface::class );

		$sut = new Cron( $api, $settings, $logger );

		$result = $sut->get_delete_local_emails_cron_hook_name();

		$this->assertEquals( 'test_cpt_name_delete_local_emails_job', $result );
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
					fn() => array()
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
					fn() => array()
				),
			)
		);

		$sut = new Cron( $api, $settings, $logger );

		$sut->background_delete_local_emails();
	}
}

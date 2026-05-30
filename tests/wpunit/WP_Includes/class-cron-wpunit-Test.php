<?php

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\Cron
 */
class Cron_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * @covers ::add_cron_jobs
	 */
	public function test_default_schedules(): void {

		$logger   = new ColorLogger();
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_friendly_name' => 'Cron Test Emails',
				'get_cron_schedules'    => array(
					'fetch_emails'        => 'hourly',
					'delete_local_emails' => 'daily',
				),
			)
		);
		$api      = $this->makeEmpty( API::class );

		$sut = new Cron( $api, $settings, $logger );

		$delete_emails_cron_name = $sut->get_delete_local_emails_cron_hook_name();

		assert( false === wp_next_scheduled( $delete_emails_cron_name ) );

		$sut->add_cron_jobs();

		$next = wp_next_scheduled( $delete_emails_cron_name );

		$this->assertNotFalse( $next );
	}
}

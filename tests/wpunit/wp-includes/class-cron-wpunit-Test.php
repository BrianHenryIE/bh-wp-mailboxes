<?php
/**
 * WPUnit tests for Cron: schedule registration and the background job delegations.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Delete_Old_Emails_Result;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\WPUnit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\Cron
 */
class Cron_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Build a Cron with an API mock and configurable cron schedules.
	 *
	 * @param ?array<string,string> $cron_schedules The schedules map, or null for the hourly/daily default.
	 * @param ?API                  $api            The API, or a fresh mock.
	 */
	private function make_sut( ?array $cron_schedules = null, ?API $api = null ): Cron {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( 'cron_test_emails' );
		$settings->allows( 'get_cron_schedules' )->andReturn(
			$cron_schedules ?? array(
				'fetch_emails'        => 'hourly',
				'delete_local_emails' => 'daily',
			)
		);

		return new Cron( $api ?? Mockery::mock( API::class ), $settings, $this->logger );
	}

	/**
	 * The fetch and delete hook names are distinct and derived from the emails CPT slug.
	 *
	 * @covers ::__construct
	 * @covers ::get_fetch_emails_cron_hook_name
	 * @covers ::get_delete_local_emails_cron_hook_name
	 */
	public function test_hook_names_are_distinct_and_cpt_derived(): void {
		$sut = $this->make_sut();

		$this->assertSame( 'cron_test_emails_fetch_emails_job', $sut->get_fetch_emails_cron_hook_name() );
		$this->assertSame( 'cron_test_emails_delete_local_emails_job', $sut->get_delete_local_emails_cron_hook_name() );
	}

	/**
	 * Both the fetch and delete jobs are scheduled by add_cron_jobs().
	 *
	 * @covers ::add_cron_jobs
	 */
	public function test_add_cron_jobs_schedules_both_jobs(): void {
		$sut = $this->make_sut();

		$this->assertFalse( wp_next_scheduled( $sut->get_fetch_emails_cron_hook_name() ) );
		$this->assertFalse( wp_next_scheduled( $sut->get_delete_local_emails_cron_hook_name() ) );

		$sut->add_cron_jobs();

		$this->assertNotFalse( wp_next_scheduled( $sut->get_fetch_emails_cron_hook_name() ) );
		$this->assertNotFalse( wp_next_scheduled( $sut->get_delete_local_emails_cron_hook_name() ) );
	}

	/**
	 * A job whose schedule is absent from settings is unscheduled.
	 *
	 * @covers ::add_cron_jobs
	 */
	public function test_add_cron_jobs_unschedules_omitted_job(): void {
		// Only fetch is configured; delete should not be scheduled.
		$sut = $this->make_sut( array( 'fetch_emails' => 'hourly' ) );

		$sut->add_cron_jobs();

		$this->assertNotFalse( wp_next_scheduled( $sut->get_fetch_emails_cron_hook_name() ) );
		$this->assertFalse( wp_next_scheduled( $sut->get_delete_local_emails_cron_hook_name() ) );
	}

	/**
	 * The background fetch job delegates to API::check_email().
	 *
	 * @covers ::background_fetch_emails
	 */
	public function test_background_fetch_emails_calls_check_email(): void {
		$api = Mockery::mock( API::class );
		$api->expects( 'check_email' )->once()->andReturn( new Check_Email_Result( true, array() ) );

		$this->make_sut( api: $api )->background_fetch_emails();
	}

	/**
	 * The background delete job delegates to API::delete_old_emails().
	 *
	 * @covers ::background_delete_local_emails
	 */
	public function test_background_delete_local_emails_calls_delete_old_emails(): void {
		$api = Mockery::mock( API::class );
		$api->expects( 'delete_old_emails' )->once()->andReturn( new Delete_Old_Emails_Result( true, 0 ) );

		$this->make_sut( api: $api )->background_delete_local_emails();
	}
}

<?php
/**
 * Regularly check for new emails.
 * Optionally delete the emails after x days.
 *
 * E.g. unsubscribe emails are only needed briefly.
 * but order reconciliation emails might want to be kept around.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Schedules with wp-cron, messages an API instance.
 *
 * @uses wp_schedule_event()
 * @uses \BrianHenryIE\WP_Mailboxes\API\API_Interface
 */
class Cron {

	use LoggerAwareTrait;

	/**
	 * Settings for configuring cron jobs.
	 *
	 * @uses BH_WP_Mailboxes_Settings_Interface::get_cron_schedules()
	 */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * Instance to invoke functions on.
	 *
	 * @uses \BrianHenryIE\WP_Mailboxes\API\API_Interface::check_email()
	 * @uses \BrianHenryIE\WP_Mailboxes\API\API_Interface::delete_old_emails()
	 */
	protected API_Interface $api;

	/**
	 * @param API_Interface                      $api BH_WP_Mailboxes main functions.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Settings for mailboxes and behaviour.
	 * @param LoggerInterface                    $logger A PSR logger.
	 */
	public function __construct( API_Interface $api, BH_WP_Mailboxes_Settings_Interface $settings, LoggerInterface $logger ) {

		$this->setLogger( $logger );
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * Build the fetch emails job name from the cpt name.
	 */
	public function get_fetch_emails_cron_hook_name(): string {
		return sanitize_key( $this->settings->get_cpt_friendly_name() ) . '_fetch_emails_job';
	}

	/**
	 * Build the delete emails job name from the cpt name.
	 */
	public function get_delete_local_emails_cron_hook_name(): string {
		return sanitize_key( $this->settings->get_cpt_friendly_name() ) . '_delete_local_emails_job';
	}

	/**
	 * Schedules or deletes the cron as per the settings.
	 *
	 * @hooked plugins_loaded
	 */
	public function add_cron_jobs(): void {

		$all_jobs = array(
			'fetch_emails'        => $this->get_fetch_emails_cron_hook_name(),
			'delete_local_emails' => $this->get_delete_local_emails_cron_hook_name(),
		);

		$scheduled_jobs_settings = $this->settings->get_cron_schedules();

		// If nothing is configured, delete all the jobs.
		if ( empty( $scheduled_jobs_settings ) ) {
			foreach ( $all_jobs as $hook ) {
				wp_unschedule_hook( $hook );
			}
			return;
		}

		foreach ( $all_jobs as $name => $hook ) {
			if ( ! isset( $scheduled_jobs_settings[ $name ] ) ) {
				wp_unschedule_hook( $hook );
				continue;
			}

			$next_scheduled_event = wp_next_scheduled( $hook );
			if ( false === $next_scheduled_event ) {
				$schedule = $scheduled_jobs_settings[ $name ];
				wp_schedule_event( time(), $schedule, $hook );
			}
		}
	}

	/**
	 * Fetch all emails for all accounts!
	 *
	 * @hooked {cpt_name}_fetch_emails_job.
	 */
	public function background_fetch_emails(): void {
		$this->logger->debug( 'Starting background_fetch_emails job from cron.' );
		$this->api->check_email();
	}

	/**
	 * Start the job to delete locally stored emails, e.g. over 30 days. The expectation is they have been
	 * processed and necessary ones have been saved/marked NOT to be deleted.
	 *
	 * @hooked {cpt_name}_delete_local_emails_job.
	 */
	public function background_delete_local_emails(): void {
		$this->logger->debug( 'Starting background_delete_local_emails job from cron.' );
		$this->api->delete_old_emails();
	}
}

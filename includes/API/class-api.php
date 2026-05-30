<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Gmail_API\Gmail_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\API\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Post;

class API implements API_Interface {

	use LoggerAwareTrait;

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/**
	 * If this is null, attachments will not be saved.
	 *
	 * @var Private_Uploads|null
	 */
	protected ?Private_Uploads $private_uploads;

	public function __construct( BH_WP_Mailboxes_Settings_Interface $settings, ?Private_Uploads $private_uploads, ?LoggerInterface $logger = null ) {

		$this->logger = $logger ?? new NullLogger();

		$this->settings        = $settings;
		$this->private_uploads = $private_uploads;
	}

	/**
	 * Fetches the emails and saves them to the cpt.
	 *
	 * Must be run after CPT is registered.
	 *
	 * @return array{success:bool}
	 */
	public function check_email(): array {

		$mailboxes = $this->settings->get_configured_mailbox_settings();

		$this->logger->debug( 'Starting check_email() for ' . count( $mailboxes ) . ' mailbox(es).' );

		$all_new_emails     = array();
		$saved_emails       = array();
		$last_fetched_times = $this->get_last_fetched_times();

		// The first time we run, we'll look back over one week of emails.
		$now_time           = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$interval_one_week  = new \DateInterval( 'P1W' );
		$first_run_datetime = $now_time->sub( $interval_one_week );
		// $now_time           = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

		foreach ( $mailboxes as $mailbox_settings ) {
			$account_name = $mailbox_settings->get_account_unique_friendly_name();
			$credentials  = $mailbox_settings->get_credentials();

			// Check if we have recently had a failed login.
			// Do not retry: some servers rate limit bad auth attempts and blacklist IPs.
			// TODO: Clear this option when settings are saved.
			// $account_name = $mailbox_settings->get_account_unique_friendly_name();

			// If there is a recorded failed login.
			if ( ! is_null( $this->get_last_failed_login_time( $account_name ) ) ) {
				$this->logger->info(
					'Too soon after failed login, please check your password and save settings to try again.',
					array(
						'account_name' => $account_name,
					)
				);
				continue;
			}

			try {
				if ( $credentials instanceof IMAP_Credentials_Interface ) {
					$fetcher = new ImapEngine_Imap_Email_Fetcher( $this->settings->get_cpt_underscored_20(), $mailbox_settings, $this->logger );
				} elseif ( $credentials instanceof Google_API_Credentials_Interface ) {
					$fetcher = new Gmail_Email_Fetcher( $this->settings->get_cpt_underscored_20(), $mailbox_settings, $this->logger );
				} else {
					// TODO filter?
					$this->logger->warning( 'No email fetcher found for credentials' );
					continue;
				}
			} catch ( \Exception $exception ) {
				$this->logger->error(
					'Failed login.',
					array(
						'account_name' => $account_name,
						'exception'    => $exception,
					)
				);
				$this->set_failed_login_time( $account_name, $now_time );
				continue;
			}

			$since_datetime = $last_fetched_times[ $account_name ] ?? $first_run_datetime;

			try {
				$all_new_account_emails = $fetcher->retrieve_emails( $since_datetime );
			} catch ( \Exception $exception ) {
				$this->logger->error(
					'Error fetching emails for ' . $account_name . '. ' . $exception->getMessage(),
					array(
						'exception'        => $exception,
						'account'          => $account_name,
						'mailbox_settings' => $mailbox_settings,
					)
				);
				continue;
			}

			$this->set_last_fetched_time( $account_name, $now_time );

			// TODO: Filter email content, from address, ... here?? (filter could be done in IMAP/Gmail search) (maybe not if it's regex).
			// TODO: Or maybe retrieve_emails() should be a concrete implementation on an abstract class

			$all_new_emails = array_merge( $all_new_emails, $all_new_account_emails );

			/**
			 * TODO: this should be done as a search in the inbox.
			 *
			 * @var BH_Email[] $filtered_account_emails
			 */
			$filtered_account_emails = array_filter(
				$all_new_account_emails,
				function ( $email ) use ( $mailbox_settings ) {
					return $this->email_filter( $email, $mailbox_settings );
				}
			);

			$cpt = $this->settings->get_cpt_underscored_20();
			/**
			 * Allow a WordPress filter to remove emails before they are saved.
			 *
			 * @param BH_Email[] $filtered_account_emails The emails filtered according to the settings.
			 */
			$filtered_account_emails = apply_filters( 'bh_wp_mailboxes_fetch_emails_complete', $filtered_account_emails, $cpt, $account_name );

			foreach ( $filtered_account_emails as $filtered_email ) {
				$filtered_email->save();
				$saved_emails[] = $filtered_email;
			}

			if ( empty( $filtered_account_emails ) ) {
				continue;
			}

			$plugin_slug   = $this->settings->get_plugin_slug();
			$mailboxes     = $this;
			$account       = $mailbox_settings;
			$new_bh_emails = $filtered_account_emails;

			$this->logger->debug( "Firing action `bh_wp_mailboxes_fetch_emails_saved_{$plugin_slug}` with " . count( $new_bh_emails ) . ' new emails' );

			/**
			 * Action fires with all new emails found.
			 *
			 * @param BH_Email[] $new_bh_emails
			 * @param Mailbox_Settings_Interface $account
			 * @param \BrianHenryIE\WP_Mailboxes\API\API $mailboxes
			 */
			do_action( "bh_wp_mailboxes_fetch_emails_saved_{$plugin_slug}", $new_bh_emails, $account, $mailboxes );

		}

		return array(
			'success'        => true,
			'all_new_emails' => $all_new_emails,
			'saved_emails'   => $saved_emails,
		);
	}


	/**
	 * TODO: This should be done when querying the server. i.e. a search during fetch.
	 *
	 * @deprecated
	 */
	protected function email_filter( BH_Email $email, Mailbox_Settings_Interface $settings ): bool {

		// Filter on the from address.
		// Forwarded emails have the wrong email address, so null can be set to null to skip the check.
		if ( ! is_null( $settings->get_from_email_regex() )
			&& 1 !== preg_match( $settings->get_from_email_regex(), $email->get_from_email() ) ) {
			$this->logger->debug( "Email from {$email->get_from_email()} did not match get_from_email_regex {$settings->get_from_email_regex()}." );
			return false;
		}

		// Filter on the body.
		// If we're using an identifier to filter emails, and it is not found, continue to the next email.
		if ( ! is_null( $settings->get_identifier_regex() )
			&& 1 !== preg_match( $settings->get_identifier_regex(), $email->get_body_plain_text() )
			&& 1 !== preg_match( $settings->get_identifier_regex(), $email->get_body_html() ) ) {
			$this->logger->debug( "Email body did not match get_identifier_regex {$settings->get_identifier_regex()}." );
			return false;
		}

		return true;
	}

	/**
	 * Return the most recently downloaded emails.
	 *
	 * @param int $number
	 *
	 * @return BH_Email[]
	 */
	public function get_downloaded_emails( int $number = 200 ): array {

		$post_type = $this->settings->get_cpt_underscored_20();

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => $number,
			)
		);

		return array_map(
			function ( WP_Post $cpt_email ) {
				return BH_Email::create_from_cpt( $cpt_email );
			},
			$query->get_posts()
		);
	}

	// local
	public function delete_old_emails(): array {
		// TODO: Implement delete_old_emails() method.

		return array(
			'success' => false,
			'message' => 'not yet implemented',
		);
	}

	/**
	 * Return the settings used to configure the instance.
	 *
	 * @return BH_WP_Mailboxes_Settings_Interface
	 */
	public function get_settings(): BH_WP_Mailboxes_Settings_Interface {
		return $this->settings;
	}

	/**
	 * Option name format is: "%s_mailbox_last_fetched_%s".
	 * e.g. "my-plugin-slug_mailbox_last_fetched_account-name".
	 *
	 * @param string $account_name The friendly account name.
	 *
	 * @return string
	 */
	protected function get_last_fetched_option_name( string $account_name ): string {
		$plugin_slug = $this->settings->get_plugin_slug();
		return sanitize_key( sprintf( '%s_mailbox_last_fetched_%s', $plugin_slug, $account_name ) );
	}

	/**
	 * @return array<string, ?DateTimeInterface>
	 */
	public function get_last_fetched_times(): array {
		$result = array();

		foreach ( $this->settings->get_configured_mailbox_settings() as $mailbox_settings ) {
			$account_name = $mailbox_settings->get_account_unique_friendly_name();

			// Should this be tied to the taxonomy somehow? (i.e. the account id should be the taxonomy id).
			$last_fetched_option_name = $this->get_last_fetched_option_name( $account_name );

			$last_fetched = get_option( $last_fetched_option_name, null );
			if ( is_null( $last_fetched ) ) {
				$result[ $account_name ] = null;
				continue;
			}
			try { // catch this exception in case the option has been manually, incorrectly modified.
				$since_datetime          = DateTime::createFromFormat( DateTime::ATOM, $last_fetched, new DateTimeZone( 'UTC' ) );
				$result[ $account_name ] = $since_datetime ?: null;
			} catch ( \Exception $exception ) {
				$this->logger->warning(
					'Could not parse date from option key ' . $last_fetched_option_name . ' with value ' . $last_fetched . '. Possibly manually edited in database. Deleting option.',
					array(
						'option_name' => $last_fetched_option_name,
						'value'       => $last_fetched,
					)
				);
				delete_option( $last_fetched_option_name );
				$result[ $account_name ] = null;
			}
		}
		return $result;
	}

	/**
	 * Save the last fetched time for this account in wp_options.
	 *
	 * @param string            $account_name The account friendly name.
	 * @param DateTimeInterface $time The time of the last successful download of emails.
	 *
	 * @return void
	 */
	public function set_last_fetched_time( string $account_name, DateTimeInterface $time ): void {

		$last_fetched_option_name = $this->get_last_fetched_option_name( $account_name );

		$atom_time = $time->format( DateTime::ATOM );

		update_option( $last_fetched_option_name, $atom_time );

		$this->logger->debug(
			'Updated option ' . $last_fetched_option_name . ' to ' . $atom_time,
			array(
				'option_name'  => $last_fetched_option_name,
				'option_value' => $atom_time,
			)
		);
	}

	/**
	 * Format: "%s_mailbox_last_failure_%s".
	 *
	 * @param string $account_name Friendly account name.
	 *
	 * @return string
	 */
	protected function get_last_failed_login_option_name( string $account_name ): string {
		$plugin_slug = $this->settings->get_plugin_slug();
		return sanitize_key( sprintf( '%s_mailbox_last_failure_%s', $plugin_slug, $account_name ) );
	}

	/**
	 * Return the last time the login failed, null if none, null if longer than the $retry_expiry_seconds, which
	 * defaults to six hours.
	 *
	 * @param string $account_name
	 * @param ?int   $retry_expiry_seconds
	 *
	 * @return ?DateTime Null is the preferred response!
	 */
	public function get_last_failed_login_time( string $account_name, ?int $retry_expiry_seconds = null ): ?DateTime {

		$option_name = $this->get_last_failed_login_option_name( $account_name );

		$atom_time = get_option( $option_name, null );

		// No recorded failed login.
		if ( is_null( $atom_time ) ) {
			return null;
		}

		$retry_expiry_seconds = $retry_expiry_seconds ?? HOUR_IN_SECONDS * 6;
		$interval             = new \DateInterval( 'PT' . $retry_expiry_seconds . 'S' );
		$now                  = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$now_sub_interval     = $now->sub( $interval );

		$last_failed_datetime = DateTime::createFromFormat( DateTime::ATOM, $atom_time, new DateTimeZone( 'UTC' ) );

		// Recorded failed login is longer than six_hours ago so we don't care.
		if ( $last_failed_datetime < $now_sub_interval ) {
			// Delete the expired time.
			$this->set_failed_login_time( $account_name, null );
			return null;
		}

		return $last_failed_datetime ?: null;
	}

	/**
	 *
	 * Set to null to clear the value... i.e. after it is successful.
	 *
	 * @param string             $account_name
	 * @param ?DateTimeInterface $time
	 */
	public function set_failed_login_time( string $account_name, ?DateTimeInterface $time ): void {
		$option_name = $this->get_last_failed_login_option_name( $account_name );
		if ( is_null( $time ) ) {
			delete_option( $option_name );
		} else {
			$atom_time = $time->format( DateTimeInterface::ATOM );
			update_option( $option_name, $atom_time );
		}
	}
}

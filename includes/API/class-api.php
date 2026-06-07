<?php

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Adapter\IMessage_BH_Email_Adapter;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Gmail_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Repository\Email_WP_Post_Repository;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class API implements API_Interface {

	use LoggerAwareTrait;

	/** @var ?Email_WP_Post_Repository Instantiated lazily on first use. */
	protected ?Email_WP_Post_Repository $email_repository = null;

	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		/**
		 * If this is null, attachments will not be saved.
		 */
		protected ?Private_Uploads $private_uploads,
		?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/** @return Email_WP_Post_Repository */
	protected function get_email_repository(): Email_WP_Post_Repository {
		if ( is_null( $this->email_repository ) ) {
			$this->email_repository = new Email_WP_Post_Repository(
				$this->settings->get_cpt_underscored_20(),
				$this->logger
			);
		}
		return $this->email_repository;
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

		/** @var BH_Email[] $all_new_emails */
		$all_new_emails     = array();
		$saved_emails       = array();
		$last_fetched_times = $this->get_last_fetched_times();

		// The first time we run, we'll look back over one week of emails.
		$now_time           = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$interval_one_week  = new \DateInterval( 'P1W' );
		$first_run_datetime = $now_time->sub( $interval_one_week );

		foreach ( $mailboxes as $mailbox_settings ) {
			$account_name = $mailbox_settings->get_account_unique_friendly_name();
			$credentials  = $mailbox_settings->get_credentials();

			// Check if we have recently had a failed login.
			// Do not retry: some servers rate limit bad auth attempts and blacklist IPs.
			// TODO: Clear this option when settings are saved.
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
				// Filter: bh_wp_mailboxes_fetcher_for_credentials( null, Account_Credentials_Interface, Mailbox_Settings_Interface, LoggerInterface ).
				// Return a non-null Email_Fetcher_Interface to supply a custom fetcher (e.g. a test stub).
				$fetcher = apply_filters( 'bh_wp_mailboxes_fetcher_for_credentials', null, $credentials, $mailbox_settings, $this->logger );

				if ( is_null( $fetcher ) ) {
					if ( $credentials instanceof IMAP_Credentials_Interface ) {
						$fetcher = new ImapEngine_Imap_Email_Fetcher( $mailbox_settings, $this->logger );
					} elseif ( $credentials instanceof Google_API_Credentials_Interface ) {
						$fetcher = new Gmail_Email_Fetcher( $mailbox_settings, $this->logger );
					} else {
						$this->logger->warning(
							'No email fetcher found for credentials type.',
							array( 'credentials_class' => get_class( $credentials ) )
						);
						continue;
					}
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

			$cpt                   = $this->settings->get_cpt_underscored_20();
			$account_category_slug = sanitize_title( $account_name );
			$mailbox_category      = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );
			$term_id               = $mailbox_category instanceof \WP_Term ? $mailbox_category->term_id : 0;

			$all_new_account_bh_emails = array();
			foreach ( $all_new_account_emails as $imessage ) {
				$all_new_account_bh_emails[] = IMessage_BH_Email_Adapter::adapt( $imessage, $cpt, $term_id );
			}

			$all_new_emails = array_merge( $all_new_emails, $all_new_account_bh_emails );

			/**
			 * TODO: this should be done as a search in the inbox.
			 *
			 * @var BH_Email[] $filtered_account_emails
			 */
			$filtered_account_emails = array_filter(
				$all_new_account_bh_emails,
				fn( BH_Email $email ): bool => $this->email_filter( $email, $mailbox_settings )
			);

			// Filter: bh_wp_mailboxes_fetch_emails_complete( BH_Email[] $emails, string $cpt, string $account_name ).
			$filtered_account_emails = apply_filters( 'bh_wp_mailboxes_fetch_emails_complete', $filtered_account_emails, $cpt, $account_name );

			foreach ( $filtered_account_emails as $filtered_email ) {
				$this->get_email_repository()->save( $filtered_email );
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
			 * @param API $mailboxes
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
	 */
	#[\Deprecated]
	protected function email_filter( BH_Email $email, Mailbox_Settings_Interface $settings ): bool {

		if ( ! is_null( $settings->get_from_email_regex() )
			&& 1 !== preg_match( $settings->get_from_email_regex(), $email->get_from_email() ) ) {
			$this->logger->debug( "Email from {$email->get_from_email()} did not match get_from_email_regex {$settings->get_from_email_regex()}." );
			return false;
		}

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
		return $this->get_email_repository()->find_recent( $number );
	}

	/**
	 * Delete locally-stored emails older than the configured retention period.
	 *
	 * @return array{success:bool, deleted:int}
	 */
	public function delete_old_emails(): array {

		$mailboxes = $this->settings->get_configured_mailbox_settings();

		$min_days = null;
		foreach ( $mailboxes as $mailbox ) {
			$days = $mailbox->get_delete_emails_days();
			if ( ! is_null( $days ) && $days > 0 ) {
				$min_days = is_null( $min_days ) ? $days : min( $min_days, $days );
			}
		}

		if ( is_null( $min_days ) ) {
			$this->logger->debug( 'Email deletion is not configured for any mailbox.' );
			return array(
				'success' => true,
				'deleted' => 0,
			);
		}

		$cutoff  = new DateTimeImmutable( "now - {$min_days} days", new DateTimeZone( 'UTC' ) );
		$deleted = $this->get_email_repository()->delete_older_than( $cutoff );

		$this->logger->info( "Deleted {$deleted} emails older than {$min_days} days." );

		return array(
			'success' => true,
			'deleted' => $deleted,
		);
	}

	/**
	 * Mark the email as read on its remote server and update local post meta.
	 *
	 * Dispatches via filter `bh_wp_mailboxes_mark_email_read` so providers can handle it.
	 * Returns true if a listener handled the action.
	 */
	public function mark_email_read( BH_Email $email ): void {
		$this->perform_remote_email_action( 'mark_read', $email );
	}

	/**
	 * Mark the email as unread on its remote server and update local post meta.
	 */
	public function mark_email_unread( BH_Email $email ): void {
		$this->perform_remote_email_action( 'mark_unread', $email );
	}

	/**
	 * Delete the email on its remote server and update local post meta.
	 */
	public function delete_email_on_server( BH_Email $email ): void {
		$this->perform_remote_email_action( 'delete_on_server', $email );
	}

	/**
	 * Shared implementation for the three remote email actions.
	 *
	 * Fires filter `bh_wp_mailboxes_remote_email_action_{$action}` with the email and resolved mailbox
	 * settings. A returning value of true signals that the action was handled.
	 * Regardless, updates the relevant post meta and inserts a log comment.
	 *
	 * @param string   $action One of: mark_read, mark_unread, delete_on_server.
	 * @param BH_Email $email  The email to act on.
	 */
	protected function perform_remote_email_action( string $action, BH_Email $email ): void {

		$mailbox_settings = $this->resolve_mailbox_for_email( $email );

		/**
		 * Filter: bh_wp_mailboxes_remote_email_action_{$action}
		 *
		 * Provider implementations should hook here to perform the remote operation.
		 * Return true to signal success, false/null to signal failure/unhandled.
		 *
		 * @param bool|null                 $handled          Start null; return true on success.
		 * @param BH_Email                  $email            The email to act on.
		 * @param ?Mailbox_Settings_Interface $mailbox_settings The resolved mailbox config, or null.
		 * @param LoggerInterface           $logger
		 */
		$handled = apply_filters( "bh_wp_mailboxes_remote_email_action_{$action}", null, $email, $mailbox_settings, $this->logger );

		$post_id = $email->get_post_id();

		if ( true !== $handled ) {
			$this->logger->warning(
				"No handler for remote email action '{$action}'.",
				array(
					'post_id'  => $post_id,
					'email_id' => $email->get_email_id(),
				)
			);
		}

		// Update post meta to reflect the new remote state.
		switch ( $action ) {
			case 'mark_read':
				update_post_meta( $post_id, 'bh_email_is_read', '1' );
				break;
			case 'mark_unread':
				update_post_meta( $post_id, 'bh_email_is_read', '0' );
				break;
			case 'delete_on_server':
				update_post_meta( $post_id, 'bh_email_deleted_on_server', '1' );
				break;
		}

		$action_label = match ( $action ) {
			'mark_read'       => 'Marked as read on server',
			'mark_unread'     => 'Marked as unread on server',
			'delete_on_server' => 'Deleted on server',
			default           => $action,
		};

		$this->insert_email_log_note( $post_id, $action_label . ( true !== $handled ? ' (no remote handler found)' : '' ) );
	}

	/**
	 * Find the Mailbox_Settings_Interface for the email's account taxonomy term.
	 *
	 * Returns null when the account cannot be matched (e.g. term was deleted).
	 */
	protected function resolve_mailbox_for_email( BH_Email $email ): ?Mailbox_Settings_Interface {

		$term_id = $email->get_account_category_id();

		foreach ( $this->settings->get_configured_mailbox_settings() as $mailbox ) {
			$slug            = sanitize_title( $mailbox->get_account_unique_friendly_name() );
			$term            = get_term_by( 'slug', $slug, 'bh-wp-mailbox-account' );
			$mailbox_term_id = $term instanceof \WP_Term ? $term->term_id : 0;
			if ( $mailbox_term_id === $term_id ) {
				return $mailbox;
			}
		}

		return null;
	}

	/**
	 * Insert a WooCommerce-style log note (wp comment) on the email post.
	 *
	 * @param int    $post_id The email CPT post ID.
	 * @param string $message The note text.
	 */
	public function insert_email_log_note( int $post_id, string $message ): void {

		wp_insert_comment(
			array(
				'comment_post_ID'    => $post_id,
				'comment_content'    => wp_kses_post( $message ),
				'comment_agent'      => 'bh-wp-mailboxes',
				'comment_type'       => 'bh_email_log',
				'comment_author'     => 'bh-wp-mailboxes',
				'comment_author_url' => '',
				'user_id'            => get_current_user_id(),
				'comment_approved'   => 1,
			)
		);
	}

	/**
	 * Return the settings used to configure the instance.
	 */
	public function get_settings(): BH_WP_Mailboxes_Settings_Interface {
		return $this->settings;
	}

	/**
	 * Option name format: "%s_mailbox_last_fetched_%s".
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

			$last_fetched_option_name = $this->get_last_fetched_option_name( $account_name );

			$last_fetched = get_option( $last_fetched_option_name, null );
			if ( is_null( $last_fetched ) ) {
				$result[ $account_name ] = null;
				continue;
			}
			try {
				$since_datetime          = DateTime::createFromFormat( DateTime::ATOM, $last_fetched, new DateTimeZone( 'UTC' ) );
				$result[ $account_name ] = false !== $since_datetime ? $since_datetime : null;
			} catch ( \Exception ) {
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
	 */
	public function set_last_fetched_time( string $account_name, DateTimeInterface $time ): void {

		$last_fetched_option_name = $this->get_last_fetched_option_name( $account_name );
		$atom_time                = $time->format( DateTime::ATOM );

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
	 */
	protected function get_last_failed_login_option_name( string $account_name ): string {
		$plugin_slug = $this->settings->get_plugin_slug();
		return sanitize_key( sprintf( '%s_mailbox_last_failure_%s', $plugin_slug, $account_name ) );
	}

	/**
	 * Return the last time the login failed. Returns null if none, or if older than $retry_expiry_seconds (default 6h).
	 *
	 * @param string $account_name
	 * @param ?int   $retry_expiry_seconds
	 *
	 * @return ?DateTime Null is the preferred response!
	 */
	public function get_last_failed_login_time( string $account_name, ?int $retry_expiry_seconds = null ): ?DateTime {

		$option_name = $this->get_last_failed_login_option_name( $account_name );
		$atom_time   = get_option( $option_name, null );

		if ( is_null( $atom_time ) ) {
			return null;
		}

		$retry_expiry_seconds ??= HOUR_IN_SECONDS * 6;
		$interval               = new \DateInterval( 'PT' . $retry_expiry_seconds . 'S' );
		$now                    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$now_sub_interval       = $now->sub( $interval );

		$last_failed_datetime = DateTime::createFromFormat( DateTime::ATOM, $atom_time, new DateTimeZone( 'UTC' ) );

		if ( $last_failed_datetime < $now_sub_interval ) {
			$this->set_failed_login_time( $account_name, null );
			return null;
		}

		return false !== $last_failed_datetime ? $last_failed_datetime : null;
	}

	/**
	 * Set to null to clear (after a successful login).
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

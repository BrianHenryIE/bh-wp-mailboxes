<?php
/**
 * Main API implementation for bh-wp-mailboxes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Account_Factory;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\IMAP_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Gmail_Email_Fetcher;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ZBateson\MailMimeParser\IMessage;

/**
 * Main API for fetching, saving, and managing emails.
 */
class API implements API_Interface {

	use LoggerAwareTrait;

	/**
	 * Email repository, instantiated lazily on first use.
	 *
	 * @var ?Email_WP_Post_Repository
	 */
	protected ?Email_WP_Post_Repository $email_repository = null;

	protected ?Email_Account_WP_Post_Repository $email_account_repository = null;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings        Plugin settings.
	 * @param ?Private_Uploads                   $private_uploads Private uploads API, or null to skip attachment saving.
	 * @param ?LoggerInterface                   $logger          PSR-3 logger.
	 */
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

	/**
	 * @return BH_Email_Account[]
	 */
	public function get_email_accounts(): array {
		$indexed_accounts = array();
		foreach ( $this->get_email_account_repository()->get_all() as $account ) {
			$indexed_accounts[ $account->email_address ] = $account;
		}
		return $indexed_accounts;
	}

	/**
	 * @param string                                         $email_address
	 * @param string                                         $display_name
	 * @param string                                         $after_download_email_action nothing|mark_read|delete
	 * @param class-string<Email_Account_Settings_Interface> $provider_type_class
	 */
	public function add_email_account(
		string $email_address,
		string $display_name,
		string $provider_type_class,
		?string $from_address_regex_filter,
		?string $body_identifier_regex_filter,
		?string $after_download_email_action,
		?int $delete_emails_after_n_days,
	): BH_Email_Account {
		$email_accounts_repository = $this->get_email_account_repository();
		if ( ! empty(
			$email_accounts_repository->query(
				email_address: $email_address
			)
		) ) {
			throw new Exception( 'already exists' );
		}
		return $email_accounts_repository->save_new(
			email_address: $email_address,
			display_name: $display_name,
			provider_type_class: $provider_type_class,
			from_address_regex_filter: $from_address_regex_filter,
			body_identifier_regex_filter: $body_identifier_regex_filter,
			after_download_email_action: $after_download_email_action,
			delete_emails_after_n_days: $delete_emails_after_n_days,
		);
	}

	/**
	 * Returns the email repository, creating it if it does not yet exist.
	 *
	 * @return Email_WP_Post_Repository
	 */
	protected function get_email_account_repository(): Email_Account_WP_Post_Repository {
		if ( is_null( $this->email_account_repository ) ) {
			$this->email_account_repository = new Email_Account_WP_Post_Repository(
				$this->settings->get_email_accounts_cpt_underscored_20(),
				new BH_Email_Account_Factory( $this->logger ),
				$this->logger
			);
		}
		return $this->email_account_repository;
	}

	/**
	 * Returns the email repository, creating it if it does not yet exist.
	 *
	 * @return Email_WP_Post_Repository
	 */
	protected function get_email_repository(): Email_WP_Post_Repository {
		if ( is_null( $this->email_repository ) ) {
			$this->email_repository = new Email_WP_Post_Repository(
				$this->settings->get_emails_cpt_underscored_20(),
				new BH_Email_Factory( $this->logger ),
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

		/**
		 * All configured mailbox account settings.
		 *
		 * @var BH_Email_Account[] $email_accounts
		 */
		$email_accounts = $this->email_account_repository->get_all();

		$this->logger->debug( 'Starting check_email() for ' . count( $email_accounts ) . ' email address(es).' );

		/**
		 * Accumulates all newly saved BH_Email objects across all accounts.
		 *
		 * @var BH_Email[] $all_new_emails
		 */
		$all_new_emails     = array();
		$saved_emails       = array();
		$last_fetched_times = $this->get_last_fetched_times();

		// The first time we run, we'll look back over one week of emails.
		$now_time           = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$interval_one_week  = new DateInterval( 'P1W' );
		$first_run_datetime = $now_time->sub( $interval_one_week );

		foreach ( $email_accounts as $email_account ) {

			if ( ! $email_account->is_active() ) {
				$this->logger->debug( 'Skipping inactive email account ' . $email_account->display_name );
				continue;
			}

			$credentials = apply_filters( 'bh_wp_mailboxes_credentials', null, $email_account );

			if ( is_null( $credentials ) || ! ( $credentials instanceof Account_Credentials_Interface ) ) {
				$this->logger->warning( 'No credentials found for ' . $email_account->display_name );
				continue;
			}

			// Check if we have recently had a failed login.
			// Do not retry: some servers rate limit bad auth attempts and blacklist IPs.
			// TODO: Clear this option when settings are saved.
			if ( ! is_null( $email_account->last_failed_login_time ) ) {

				// If last failure time was less than four hours ago, skip.
				if ( $email_account->last_failed_login_time->diff( new DateTimeImmutable() ) < new DateInterval( 'PT4H' ) ) {
					$this->logger->info(
						'Too soon after failed login, please check your password and save settings to try again.',
						array(
							'account_name' => $email_account->display_name,
						)
					);
					continue;
				}
			}

			try {
				// Filter: bh_wp_mailboxes_fetcher_for_credentials( null, Account_Credentials_Interface, Mailbox_Settings_Interface, LoggerInterface ).
				// Return a non-null Email_Fetcher_Interface to supply a custom fetcher (e.g. a test stub).
				$fetcher = apply_filters( 'bh_wp_mailboxes_fetcher_for_credentials', null, $credentials, $email_account, $this->logger );

				if ( is_null( $fetcher ) ) {
					if ( $credentials instanceof IMAP_Credentials_Interface ) {
						$fetcher = new ImapEngine_Imap_Email_Fetcher( $email_account, $credentials, $this->logger );
					} elseif ( $credentials instanceof Google_API_Credentials_Interface ) {
						$fetcher = new Gmail_Email_Fetcher( $email_account, $credentials, $this->logger );
					} else {
						$this->logger->warning(
							'No email fetcher found for credentials type.',
							array( 'credentials_class' => get_class( $credentials ) )
						);
						continue;
					}
				}
			} catch ( Exception $exception ) {
				$this->logger->error(
					'Failed login.',
					array(
						'account_name' => $email_account->display_name,
						'exception'    => $exception,
					)
				);
				$this->set_failed_login_time( $email_account->display_name, $now_time );
				continue;
			}

			$since_datetime = $last_fetched_times[ $email_account->display_name ] ?? $first_run_datetime;

			try {
				$all_new_account_emails = $fetcher->retrieve_emails( $since_datetime );
			} catch ( Exception $exception ) {
				$this->logger->error(
					'Error fetching emails for ' . $email_account->display_name . '. ' . $exception->getMessage(),
					array(
						'exception'        => $exception,
						'account'          => $email_account->display_name,
						'mailbox_settings' => $email_account,
					)
				);
				continue;
			}

			$this->set_last_fetched_time( $email_account->display_name, $now_time );

			$cpt = $this->settings->get_emails_cpt_underscored_20();
			// $account_category_slug = sanitize_title( $account_name );
			// $mailbox_category      = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );
			// $term_id               = $mailbox_category instanceof \WP_Term ? $mailbox_category->term_id : 0;

			/**
			 * Newly saved BH_Email objects for this account.
			 *
			 * @var BH_Email[] $all_new_account_bh_emails
			 */
			$all_new_account_bh_emails = $this->get_email_repository()->save_all( $all_new_account_emails, $this->settings, $email_account );

			$all_new_emails = array_merge( $all_new_emails, $all_new_account_bh_emails );
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
	// protected function email_filter( IMessage $email, Email_Account_Settings_Interface $settings ): bool {
	//
	// if ( ! is_null( $settings->get_from_email_regex() )
	// && 1 !== preg_match( $settings->get_from_email_regex(), $email->get_from_email() ) ) {
	// $this->logger->debug( "Email from {$email->get_from_email()} did not match get_from_email_regex {$settings->get_from_email_regex()}." );
	// return false;
	// }
	//
	// if ( ! is_null( $settings->get_identifier_regex() )
	// && 1 !== preg_match( $settings->get_identifier_regex(), $email->body_plain_text )
	// && 1 !== preg_match( $settings->get_identifier_regex(), $email->get_body_html() ) ) {
	// $this->logger->debug( "Email body did not match get_identifier_regex {$settings->get_identifier_regex()}." );
	// return false;
	// }
	//
	// return true;
	// } // end email_filter.

	/**
	 * Return the most recently downloaded emails.
	 *
	 * @param int $number Maximum number of emails to return.
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

		$email_accounts = $this->get_email_account_repository()->get_all( status: 'active' );

		$min_days = null;
		foreach ( $email_accounts as $email_account ) {
			$days = $email_account->get_delete_emails_days();
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
	 *
	 * @param BH_Email $email The email to mark as read.
	 */
	public function mark_email_read( BH_Email $email ): void {
		$this->perform_remote_email_action( 'mark_read', $email );
	}

	/**
	 * Mark the email as unread on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to mark as unread.
	 */
	public function mark_email_unread( BH_Email $email ): void {
		$this->perform_remote_email_action( 'mark_unread', $email );
	}

	/**
	 * Delete the email on its remote server and update local post meta.
	 *
	 * @param BH_Email $email The email to delete on the server.
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

		$mailbox_settings = $this->resolve_email_account_for_email( $email );

		/**
		 * Filter: bh_wp_mailboxes_remote_email_action_{$action}
		 *
		 * Provider implementations should hook here to perform the remote operation.
		 * Return true to signal success, false/null to signal failure/unhandled.
		 *
		 * @param bool|null                 $handled          Start null; return true on success.
		 * @param BH_Email                  $email            The email to act on.
		 * @param ?Email_Account_Settings_Interface $mailbox_settings The resolved mailbox config, or null.
		 * @param LoggerInterface           $logger
		 */
		$handled = apply_filters( "bh_wp_mailboxes_remote_email_action_{$action}", null, $email, $mailbox_settings, $this->logger );

		$post_id = $email->get_post_id();

		if ( true !== $handled ) {
			$this->logger->warning(
				"No handler for remote email action '{$action}'.",
				array(
					'post_id'  => $post_id,
					'email_id' => $email->message_id,
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
	 *
	 * @param BH_Email $email The email to resolve the account for.
	 */
	protected function resolve_email_account_for_email( BH_Email $email ): ?Email_Account_Settings_Interface {

		$post             = get_post( $email->get_post_id() );
		$email_account_id = $post ? $post->post_parent : 0;

		// TODO: Previously the idea was to use taxonomies. I think it's better to use a CPT for each email_account and use it as the parent_post id.

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
	 *
	 * @param string $account_name The account's unique friendly name.
	 */
	protected function get_last_fetched_option_name( string $account_name ): string {
		$plugin_slug = $this->settings->get_plugin_slug();
		return sanitize_key( sprintf( '%s_mailbox_last_fetched_%s', $plugin_slug, $account_name ) );
	}

	/**
	 * Returns the last-fetched times for all configured mailbox accounts.
	 *
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
			$since_datetime = DateTime::createFromFormat( DateTime::ATOM, $last_fetched, new DateTimeZone( 'UTC' ) );
			if ( false === $since_datetime ) {
				$this->logger->warning(
					'Could not parse date from option key ' . $last_fetched_option_name . ' with value ' . $last_fetched . '. Possibly manually edited in database. Deleting option.',
					array(
						'option_name' => $last_fetched_option_name,
						'value'       => $last_fetched,
					)
				);
				delete_option( $last_fetched_option_name );
				$result[ $account_name ] = null;
			} else {
				$result[ $account_name ] = $since_datetime;
			}
		}
		return $result;
	}

	/**
	 * Save the last fetched time for this account in wp_options.
	 *
	 * @param string            $account_name The account's unique friendly name.
	 * @param DateTimeInterface $time         The time to save.
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
	 *
	 * @param string $account_name The account's unique friendly name.
	 */
	protected function get_last_failed_login_option_name( string $account_name ): string {
		$plugin_slug = $this->settings->get_plugin_slug();
		return sanitize_key( sprintf( '%s_mailbox_last_failure_%s', $plugin_slug, $account_name ) );
	}

	/**
	 * Return the last time the login failed. Returns null if none, or if older than $retry_expiry_seconds (default 6h).
	 *
	 * @param string $account_name         The account's unique friendly name.
	 * @param ?int   $retry_expiry_seconds Seconds before a failed login is forgotten.
	 *
	 * @return ?DateTime Null is the preferred response.
	 */
	public function get_last_failed_login_time( string $account_name, ?int $retry_expiry_seconds = null ): ?DateTime {

		$option_name = $this->get_last_failed_login_option_name( $account_name );
		$atom_time   = get_option( $option_name, null );

		if ( is_null( $atom_time ) ) {
			return null;
		}

		$retry_expiry_seconds ??= HOUR_IN_SECONDS * 6;
		$interval               = new DateInterval( 'PT' . $retry_expiry_seconds . 'S' );
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
	 *
	 * @param string             $account_name The account's unique friendly name.
	 * @param ?DateTimeInterface $time         The failure time, or null to clear.
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

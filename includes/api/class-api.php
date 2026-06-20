<?php
/**
 * Main API implementation for bh-wp-mailboxes.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API;

use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_Account_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Provider;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Gmail_Email_Provider;
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Check_Email_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Delete_Old_Emails_Result;
use BrianHenryIE\WP_Mailboxes\API\Model\Result\Test_Connection_Result;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Private_Uploads\API\API as Private_Uploads;
use DateException;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Main API for fetching, saving, and managing emails.
 */
class API implements API_Interface {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings                 Plugin settings.
	 * @param Email_WP_Post_Repository           $email_repository         Repository for saved emails.
	 * @param Email_Account_WP_Post_Repository   $email_account_repository Repository for email accounts.
	 * @param ?Private_Uploads                   $private_uploads          Private uploads API, or null to skip attachment saving.
	 * @param ?LoggerInterface                   $logger                   PSR-3 logger.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Email_WP_Post_Repository $email_repository,
		protected Email_Account_WP_Post_Repository $email_account_repository,
		protected ?Private_Uploads $private_uploads,
		?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Returns all email accounts indexed by email address.
	 *
	 * @return BH_Email_Account[]
	 */
	public function get_email_accounts(): array {
		$indexed_accounts = array();
		foreach ( $this->email_account_repository->get_all() as $account ) {
			$indexed_accounts[ $account->email_address ] = $account;
		}
		return $indexed_accounts;
	}

	/**
	 * Add a new email account configuration.
	 *
	 * @param string  $email_address               The mailbox address.
	 * @param string  $display_name                Human-readable account name.
	 * @param string  $provider_type_class         Provider class to use for fetching (class-string<Email_Fetcher_Interface>).
	 * @param ?string $from_address_regex_filter   Optional regex to filter incoming senders.
	 * @param ?string $body_identifier_regex_filter Optional regex to filter email bodies.
	 * @param ?string $after_download_remote_email_action One of: nothing, mark_read, delete.
	 * @param ?int    $delete_local_emails_after_n_days  Days before locally-saved emails are purged.
	 *
	 * @throws Exception When an account with this email address already exists.
	 */
	public function add_email_account(
		string $email_address,
		string $display_name,
		string $provider_type_class,
		?string $from_address_regex_filter,
		?string $body_identifier_regex_filter,
		?string $after_download_remote_email_action,
		?int $delete_local_emails_after_n_days,
	): BH_Email_Account {
		// Uniqueness (no existing account for this address) is enforced by the repository's save_new().
		return $this->email_account_repository->save_new(
			email_address: $email_address,
			display_name: $display_name,
			provider_type_class: $provider_type_class,
			from_address_regex_filter: $from_address_regex_filter,
			body_identifier_regex_filter: $body_identifier_regex_filter,
			after_download_remote_email_action: $after_download_remote_email_action,
			delete_local_emails_after_n_days: $delete_local_emails_after_n_days,
		);
	}

	/**
	 * Fetches the emails and saves them to the cpt.
	 *
	 * Must be run after CPT is registered.
	 */
	public function check_email(): Check_Email_Result {

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
		$last_fetched_times = $this->get_last_fetched_times( $email_accounts );

		// The first time we run, we'll look back over one week of emails.
		$now_time           = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$interval_one_week  = new DateInterval( 'P1W' );
		$first_run_datetime = $now_time->sub( $interval_one_week );

		foreach ( $email_accounts as $email_account ) {
			$fetched        = $this->fetch_for_account( $email_account, $last_fetched_times[ $email_account->email_address ] ?? $first_run_datetime, $now_time );
			$all_new_emails = array_merge( $all_new_emails, $fetched );
		}

		/**
		 * Fires after all new emails have been fetched and saved across every account.
		 *
		 * @param BH_Email[] $all_new_emails Every newly saved BH_Email object.
		 */
		do_action( 'bh_wp_mailboxes_fetch_emails_saved_' . $this->settings->get_plugin_slug(), $all_new_emails );

		/**
		 * Fires once check_email() has finished, regardless of how many emails were saved.
		 *
		 * @param BH_Email[] $all_new_emails Every newly saved BH_Email object.
		 */
		do_action( 'bh_wp_mailboxes_fetch_emails_complete', $all_new_emails );

		return new Check_Email_Result( success: true, new_emails: $all_new_emails );
	}

	/**
	 * Fetches new emails for a single account, saves them, and updates the account's last-checked time.
	 *
	 * Shared by check_email() (loop) and check_email_for_account() (single).
	 *
	 * @param BH_Email_Account  $email_account   The account to fetch for.
	 * @param DateTimeInterface $since_datetime  Fetch emails newer than this time.
	 * @param DateTimeImmutable $now_time        Current time, used to update last-checked meta.
	 *
	 * @return BH_Email[] Newly saved emails for the account.
	 *
	 * @throws Exception When required credentials are not found.
	 */
	protected function fetch_for_account( BH_Email_Account $email_account, DateTimeInterface $since_datetime, DateTimeImmutable $now_time ): array {

		if ( ! $email_account->is_active() ) {
			$this->logger->debug( 'Skipping inactive email account ' . $email_account->display_name );
			return array();
		}

		$plugin_slug = $this->settings->get_plugin_slug();

		$provider = $this->get_provider_for_email_account( $email_account );

		if ( is_null( $provider ) ) {
			$this->logger->warning( 'No fetcher found for ' . $email_account->display_name );
			return array();
		}

		// Receive-only providers (e.g. webhook / AWS SNS) cannot be polled; there is nothing to fetch.
		if ( ! ( $provider instanceof Supports_Fetching ) ) {
			$this->logger->debug( $email_account->display_name . ' provider does not support fetching; skipping.' );
			return array();
		}

		if ( $provider instanceof Requires_Credentials ) {

			try {
				/**
				 * Given the email account, get the credentials required for its provider.
				 *
				 * @param ?Account_Credentials_Interface $credentials The null value being filtered which should return Account_Credentials_Interface instance.
				 * @param string $plugin_slug To allow multiple plugins (and potentially library verions) to use this same filter name.
				 * @param BH_Email_Account $email_account The account config to get credentials for {@see BH_Email_Account::$provider_type_class}.
				 */
				$credentials = apply_filters( 'bh_wp_mailboxes_credentials', null, $plugin_slug, $email_account );
			} catch ( Throwable $throwable ) {

				// E.g. "Too few arguments to function..." which means the `add_filter()` implementation is incorrect.
				$this->logger->error( $throwable->getMessage() );

				throw new Exception( 'Error discovering account credentials.' );
			}

			if ( ! ( $credentials instanceof Account_Credentials_Interface ) ) {
				$this->logger->warning( 'No credentials found for ' . $email_account->display_name );

				return array();
			}

			// Only rate limit cron jobs. Manual fetching should alway attempt.
			if ( ! is_null( $email_account->last_failed_login_time ) && wp_doing_cron() ) {
				if ( $email_account->last_failed_login_time > ( new DateTime() )->sub( new DateInterval( 'PT4H' ) ) ) {
					$this->logger->info(
						'Too soon after failed login, please check your password and save settings to try again.',
						array( 'account_name' => $email_account->display_name )
					);

					return array();
				}
			}
			$provider->set_credentials( $credentials );
		}

		try {
			$all_new_account_emails = $provider->retrieve_emails( $since_datetime );
		} catch ( Exception | ImapConnectionClosedException $exception ) {
			$this->logger->error(
				'Error fetching emails for ' . $email_account->display_name . '. ' . $exception->getMessage(),
				array(
					'exception'        => $exception,
					'account'          => $email_account->display_name,
					'mailbox_settings' => $email_account,
				)
			);
			// Record the failure time so the next four hours of cron runs skip this account.
			$this->email_account_repository->update( $email_account, last_failed_login_time: $now_time );

			return array();
		}

		// The fetch authenticated and completed, so record the successful login and check time.
		$this->email_account_repository->update(
			$email_account,
			last_checked_time: $now_time,
			last_successful_login_time: $now_time,
		);

		// Drop any emails already saved locally (same account + Message-ID) so we never duplicate.
		$all_new_account_emails = $all_new_account_emails->reject(
			fn ( Fetched_Email $unsaved_email ): bool => $this->email_repository->is_post_for_message_id(
				$email_account->email_address,
				$unsaved_email->message->getMessageId() ?? ''
			)
		);

		// TODO: Log the number of emails found.
		$saved = $this->email_repository->save_all( $all_new_account_emails, $this->settings, $email_account, $this->private_uploads );

		// If the mailbox is configured to mark-as-read or delete emails on the server after downloading,
		// perform that action now. The mark/delete methods record their own log entry on each email.
		$after_download_action = $email_account->after_download_remote_email_action();
		if ( in_array( $after_download_action, array( 'mark_read', 'delete' ), true ) ) {
			foreach ( $saved as $bh_email ) {
				try {
					if ( 'mark_read' === $after_download_action ) {
						$this->mark_email_read( $bh_email );
					} else {
						$this->delete_email_on_server( $bh_email );
					}
				} catch ( Throwable $exception ) {
					$this->logger->warning(
						'Post-download action "' . $after_download_action . '" failed: ' . $exception->getMessage(),
						array( 'post_id' => $bh_email->get_post_id() )
					);
				}
			}
		}

		return $saved;
	}

	/**
	 * Fetches new emails for a single account and saves them.
	 *
	 * @param BH_Email_Account   $account The account to check.
	 * @param ?DateTimeInterface $since Time to find new emails after.
	 *
	 * @throws DateException In the unlikely event PHP is unable to create now@UTC.
	 */
	public function check_email_for_account( BH_Email_Account $account, ?DateTimeInterface $since = null ): Check_Email_Result {
		$now_time = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$since    = $since ?? $account->last_successful_login_time ?? $now_time->sub( new DateInterval( 'P1W' ) );
		$saved    = $this->fetch_for_account( $account, $since, $now_time );
		return new Check_Email_Result( success: true, new_emails: $saved );
	}

	/**
	 * Validate an account's credentials by connecting to the server.
	 *
	 * If a provider doesn't need credentials, return the last email time and the user can use that to infer the health.
	 *
	 * @param BH_Email_Account               $account     The account whose provider to connect with.
	 * @param ?Account_Credentials_Interface $credentials Candidate credentials, or null to resolve via filter.
	 */
	public function test_connection( BH_Email_Account $account, ?Account_Credentials_Interface $credentials = null ): Test_Connection_Result {

		$provider = $this->get_provider_for_email_account( $account );

		if ( is_null( $provider ) ) {
			return new Test_Connection_Result( success: false, message: 'No email provider found for ' . $account->display_name . '.' );
		}

		if ( $provider instanceof Requires_Credentials ) {
			$plugin_slug = $this->settings->get_plugin_slug();
			$credentials = $credentials ?? apply_filters( 'bh_wp_mailboxes_credentials', $plugin_slug, null, $account );

			if ( ! ( $credentials instanceof Account_Credentials_Interface ) ) {
				return new Test_Connection_Result( success: false, message: 'No credentials found for ' . $account->display_name . '.' );
			}

			$provider->set_credentials( $credentials );
		}

		try {
			$provider->test_connection();

			return new Test_Connection_Result( success: true, message: 'Connected successfully.' );
		} catch ( Throwable $exception ) {
			$this->logger->warning(
				'Connection test failed for ' . $account->display_name . '. ' . $exception->getMessage(),
				array(
					'account'   => $account->display_name,
					'exception' => $exception,
				)
			);

			return new Test_Connection_Result( success: false, message: $exception->getMessage() );
		}
	}

	/**
	 * Return the most recently downloaded emails.
	 *
	 * @param int $number Maximum number of emails to return.
	 *
	 * @return BH_Email[]
	 */
	public function get_downloaded_emails( int $number = 200 ): array {
		return $this->email_repository->find_recent( $number );
	}

	/**
	 * Delete locally-stored emails older than the configured retention period.
	 */
	public function delete_old_emails(): Delete_Old_Emails_Result {

		$email_accounts = $this->email_account_repository->get_all( status: 'active' );

		$min_days = null;
		foreach ( $email_accounts as $email_account ) {
			$days = $email_account->get_delete_emails_days();
			if ( ! is_null( $days ) && $days > 0 ) {
				$min_days = is_null( $min_days ) ? $days : min( $min_days, $days );
			}
		}

		if ( is_null( $min_days ) ) {
			$this->logger->debug( 'Email deletion is not configured for any mailbox.' );
			return new Delete_Old_Emails_Result( success: true, deleted_count: 0 );
		}

		$cutoff  = new DateTimeImmutable( "now - {$min_days} days", new DateTimeZone( 'UTC' ) );
		$deleted = $this->email_repository->delete_older_than( $cutoff );

		$this->logger->info( "Deleted {$deleted} local emails older than {$min_days} days." );

		return new Delete_Old_Emails_Result( success: true, deleted_count: $deleted );
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
	 * Change an email's local status. The repository records the change in the email's log.
	 *
	 * @param BH_Email $email        The email to update.
	 * @param string   $local_status The new local (WordPress post) status.
	 *
	 * @throws Exception On failure to save.
	 */
	public function update_email_status( BH_Email $email, string $local_status ): BH_Email {
		return $this->email_repository->update( $email, local_status: $local_status );
	}

	/**
	 * Apply the account's credentials to a provider that requires them.
	 *
	 * Resolves the credentials via the `bh_wp_mailboxes_credentials` filter (args: value, plugin_slug, account)
	 * and sets them on the provider. No-op for providers that do not implement {@see Requires_Credentials}.
	 *
	 * @param Email_Provider_Interface $provider      The provider to credential.
	 * @param BH_Email_Account         $email_account The account whose credentials to resolve.
	 *
	 * @throws \InvalidArgumentException When the filter does not return an Account_Credentials_Interface.
	 */
	protected function set_provider_credentials( Email_Provider_Interface $provider, BH_Email_Account $email_account ): void {

		if ( ! ( $provider instanceof Requires_Credentials ) ) {
			return;
		}

		$plugin_slug = $this->settings->get_plugin_slug();

		/**
		 * Resolve the account's credentials.
		 *
		 * @see API::fetch_for_account()
		 */
		$credentials = apply_filters( 'bh_wp_mailboxes_credentials', null, $plugin_slug, $email_account );

		if ( ! ( $credentials instanceof Account_Credentials_Interface ) ) {
			throw new \InvalidArgumentException( 'Credentials were not Account_Credentials_Interface' );
		}

		$provider->set_credentials( $credentials );
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
	 *
	 * @throws Exception When expected email account / provider / remote coordinates are not found.
	 */
	protected function perform_remote_email_action( string $action, BH_Email $email ): void {

		$email_account = $this->get_email_account_for_email( $email );

		if ( is_null( $email_account ) ) {
			throw new Exception( 'failed to get BH_Email_Account for email ' . esc_html( $email->post_type ) );
		}

		$provider = $this->get_provider_for_email_account( $email_account );
		$post_id  = $email->get_post_id();

		if ( is_null( $provider ) ) {
			throw new Exception( 'No provider found for ' . esc_html( $email_account->display_name ) );
		}

		$this->set_provider_credentials( $provider, $email_account );

		if ( is_null( $email->get_remote_coordinates() ) ) {
			throw new Exception( 'No remote coordinates found for ' . esc_html( $email_account->display_name ) );
		}

		switch ( $action ) {
			case 'mark_read':
				try {
					$provider->set_is_marked_read( $email->get_remote_coordinates(), true );
					$this->email_repository->update( $email, is_remote_read: true );
					// Reversible change → info.
					$this->insert_email_log_note( $post_id, 'Marked as read on server', 'info' );
				} catch ( Throwable $exception ) {
					$this->insert_email_log_note( $post_id, 'Failed to mark as read on server.', 'error' );
				}
				break;
			case 'mark_unread':
				try {
					$provider->set_is_marked_read( $email->get_remote_coordinates(), false );
					$this->email_repository->update( $email, is_remote_read: false );
					// Reversible change → info.
					$this->insert_email_log_note( $post_id, 'Marked as unread on server', 'info' );
				} catch ( Throwable $exception ) {
					$this->insert_email_log_note( $post_id, 'Failed to mark as unread on server.', 'error' );
				}
				break;
			case 'delete_on_server':
				try {
					$provider->do_delete_on_server( $email->get_remote_coordinates() );
					$this->email_repository->update( $email, is_remote_deleted: true );
					// Intentional irreversible change → notice.
					$this->insert_email_log_note( $post_id, 'Deleted on server', 'notice' );
				} catch ( Throwable $exception ) {
					$this->insert_email_log_note( $post_id, 'Failed delete email on server.', 'error' );
				}
				break;
		}
	}

	/**
	 * Insert a WooCommerce-style log note (wp comment) on the email post.
	 *
	 * @param int    $post_id The email CPT post ID.
	 * @param string $message The note text.
	 * @param string $level   Log level: `info`, `notice`, `warning`, or `error`.
	 */
	public function insert_email_log_note( int $post_id, string $message, string $level = 'info' ): void {

		$email = $this->email_repository->find_by_post_id( $post_id );

		$this->email_repository->log( $email, $message, false, array(), $level );
	}

	/**
	 * Fetch the live read status from the remote server for an email.
	 *
	 * @param BH_Email $email The email to query.
	 *
	 * @return ?bool True/false when known, null when it cannot be determined.
	 */
	public function get_remote_read_status( BH_Email $email ): ?bool {

		$email_account = $this->get_email_account_for_email( $email );
		if ( is_null( $email_account ) ) {
			return null;
		}

		$provider    = $this->get_provider_for_email_account( $email_account );
		$coordinates = $email->get_remote_coordinates();

		if ( is_null( $provider ) || is_null( $coordinates ) || ! $provider->can_read_status() ) {
			return null;
		}

		try {
			$this->set_provider_credentials( $provider, $email_account );
			return $provider->get_is_marked_read( $coordinates );
		} catch ( Throwable $exception ) {
			$this->logger->warning(
				'Failed to fetch remote read status: ' . $exception->getMessage(),
				array( 'post_id' => $email->get_post_id() )
			);
			return null;
		}
	}

	/**
	 * Return the settings used to configure the instance.
	 */
	public function get_settings(): BH_WP_Mailboxes_Settings_Interface {
		return $this->settings;
	}

	/**
	 * Returns the last-fetched times for all configured mailbox accounts.
	 *
	 * @param BH_Email_Account[] $email_accounts Specific accounts to return the times for, otherwise all are returned.
	 *
	 * @return array<string, ?DateTimeInterface>
	 */
	public function get_last_fetched_times( ?array $email_accounts = null ): array {
		$result = array();

		$email_accounts ??= $this->get_email_accounts();

		foreach ( $email_accounts as $email_account ) {
			$result[ $email_account->email_address ] = $email_account->last_successful_login_time;
		}
		return $result;
	}

	/**
	 * Return the email account for an email post, or null if the post/parent was deleted.
	 *
	 * @param BH_Email $email The email whose account to resolve.
	 */
	public function get_email_account_for_email( BH_Email $email ): ?BH_Email_Account {
		$post = get_post( $email->post_id );
		if ( ! $post || ! $post->post_parent ) {
			return null;
		}
		try {
			return $this->email_account_repository->find_by_post_id( $post->post_parent );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Return the email fetcher for a given account.
	 *
	 * Applies filter `bh_wp_mailboxes_provider_for_account` so callers can inject a custom
	 * fetcher (e.g. a stub in tests or the development plugin).
	 *
	 * @param BH_Email_Account $email_account The account to find a fetcher for.
	 */
	public function get_provider_for_email_account( BH_Email_Account $email_account ): ?Email_Provider_Interface {

		$plugin_slug = $this->settings->get_plugin_slug();

		/**
		 * Get an Email_Provider_Interface instance for a BH_Email_Account.
		 *
		 * @param mixed|Email_Provider_Interface  $provider The email fetcher for the account, or null if none is found.
		 * @param string $plugin_slug To allow multiple plugins (and potentially library verions) to use this same filter name.
		 * @param BH_Email_Account $email_account The account config to get provider for {@see BH_Email_Account::$provider_type_class}.
		 */
		$provider = apply_filters( 'bh_wp_mailboxes_provider_for_account', null, $plugin_slug, $email_account );

		if ( $provider instanceof Email_Provider_Interface ) {
			return $provider;
		}

		if ( ImapEngine_Imap_Email_Provider::class === $email_account->provider_type_class ) {
			return new ImapEngine_Imap_Email_Provider( $email_account, $this->logger );
		} elseif ( Google_API_Credentials_Interface::class === $email_account->provider_type_class ) {
			return new Gmail_Email_Provider( $email_account, $this->logger );
		} else {
			$this->logger->warning(
				'No email fetcher found for provider type: {provider_type_class}',
				array( 'provider_type_class' => $email_account->provider_type_class )
			);
			return null;
		}
	}
}

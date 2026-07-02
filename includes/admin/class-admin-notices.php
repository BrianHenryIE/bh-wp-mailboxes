<?php
/**
 * Admin notice shown when a mailbox account's most recent fetch failed to connect.
 *
 * Built on the wptrt/admin-notices library so dismissal is handled for us. An account is "failing" when it
 * has a `last_failed_login_time` and no more-recent `last_successful_login_time`, so the notice self-clears
 * once a later success is recorded. Each notice's id embeds the failure time, so dismissing a notice only
 * hides that specific failure — a later, different failure produces a new id and is shown again.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WPTRT\AdminNotices\Notices;

/**
 * Registers a dismissible per-account "could not connect" notice on the emails list screen.
 */
class Admin_Notices {

	use LoggerAwareTrait;

	/**
	 * Prefix for each notice's unique id, completed as `{prefix}-{account_post_id}-{failure_timestamp}`.
	 */
	private const NOTICE_ID_PREFIX = 'bh-wp-mailboxes-auth-failure';

	/**
	 * Prefix wptrt uses to build the per-notice dismissal user-meta key.
	 */
	private const DISMISS_OPTION_PREFIX = 'bh_wp_mailboxes_auth_failure_dismissed';

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api      Main API instance (to enumerate accounts).
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings (emails CPT / screen scoping).
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 * @param Notices                            $notices  The wptrt notices registry (injectable for tests).
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
		protected Notices $notices = new Notices(),
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Register the failure notices for rendering on the emails list screen.
	 *
	 * Hooked on `current_screen`, which fires before `admin_enqueue_scripts` so wptrt can still enqueue its
	 * dismiss script. Skips AJAX and every screen other than the emails list.
	 *
	 * @hooked current_screen
	 */
	public function render_on_emails_screen(): void {

		if ( wp_doing_ajax() ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || $screen->id !== $this->emails_list_screen_id() ) {
			return;
		}

		$this->add_failure_notices();
		$this->notices->boot();
	}

	/**
	 * Re-register the failure notices during a wptrt dismiss AJAX request so the library's handler can match
	 * the submitted notice id and persist the dismissal.
	 *
	 * Hooked on `wp_loaded` because — unlike `current_screen`/`admin_init` — it fires on `admin-ajax.php`.
	 *
	 * @hooked wp_loaded
	 */
	public function register_dismiss_handler(): void {

		if ( ! wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only branching on the action; wptrt verifies its own nonce.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: '';
		if ( 'wptrt_dismiss_notice' !== $action ) {
			return;
		}

		$this->add_failure_notices();
	}

	/**
	 * Add a wptrt notice for every account whose most recent fetch failed and isn't already dismissed.
	 */
	private function add_failure_notices(): void {

		$plugin_slug = $this->settings->get_plugin_slug();
		$screen_id   = $this->emails_list_screen_id();

		foreach ( $this->api->get_email_accounts() as $account ) {

			$failed = $account->last_failed_login_time;
			if ( null === $failed || ! $this->is_failing( $account ) ) {
				continue;
			}

			$id = self::NOTICE_ID_PREFIX . '-' . $account->get_post_id() . '-' . $failed->getTimestamp();

			// Skip already-dismissed notices so wptrt v1.0.4's dismiss script does not run against an absent
			// element. Mirrors WPTRT\AdminNotices\Dismiss::is_dismissed() for the 'user' scope.
			if ( $this->is_dismissed( $id ) ) {
				continue;
			}

			/**
			 * Filter the auth-failure notice message — e.g. a consuming plugin can return a message linking
			 * to its own settings screen (wptrt permits `a`, `em`, `strong`, `br`, `p` tags in the message).
			 *
			 * @param string           $message     The default notice message.
			 * @param BH_Email_Account $account     The failing account.
			 * @param string           $plugin_slug The plugin slug the library is running as.
			 */
			$message = apply_filters(
				'bh_wp_mailboxes_auth_failure_notice_message',
				$this->default_message( $account ),
				$account,
				$plugin_slug
			);

			$this->notices->add(
				$id,
				'',
				$message,
				array(
					'type'          => 'error',
					'scope'         => 'user',
					'capability'    => 'edit_posts',
					'option_prefix' => self::DISMISS_OPTION_PREFIX,
					'screens'       => array( $screen_id ),
				)
			);
		}
	}

	/**
	 * The default notice message naming the account.
	 *
	 * @param BH_Email_Account $account The failing account.
	 */
	private function default_message( BH_Email_Account $account ): string {
		return sprintf(
			/* translators: %s: the email account address. */
			__( 'bh-wp-mailboxes could not connect to the account “%s” on the last attempt. Please check the connection settings.', 'bh-wp-mailboxes' ),
			$account->get_account_email_address()
		);
	}

	/**
	 * Whether the current user has already dismissed the notice with this id (wptrt 'user' scope).
	 *
	 * @param string $id The notice id.
	 */
	private function is_dismissed( string $id ): bool {
		$key = sanitize_key( self::DISMISS_OPTION_PREFIX ) . '_' . sanitize_key( $id );
		return (bool) get_user_meta( get_current_user_id(), $key, true );
	}

	/**
	 * The `WP_Screen::$id` of the emails list table (e.g. `edit-fixtures_email`).
	 */
	private function emails_list_screen_id(): string {
		return 'edit-' . $this->settings->get_emails_cpt_underscored_20();
	}

	/**
	 * Whether the account's most recent login attempt failed (a failure with no later success).
	 *
	 * @param BH_Email_Account $account The account to inspect.
	 */
	private function is_failing( BH_Email_Account $account ): bool {

		$failed = $account->last_failed_login_time;
		if ( null === $failed ) {
			return false;
		}

		$succeeded = $account->last_successful_login_time;

		return null === $succeeded || $succeeded < $failed;
	}
}

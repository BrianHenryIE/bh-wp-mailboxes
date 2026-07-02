<?php
/**
 * Admin notice shown when a mailbox account's most recent fetch failed to connect.
 *
 * The notice is derived from the account's recorded times rather than stored separately: an account is
 * "failing" when it has a `last_failed_login_time` and no more-recent `last_successful_login_time`. That
 * makes the notice self-clearing — the next successful fetch records a newer success time and the notice
 * disappears on the next page load. No separate "clear" step or option to keep in sync.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Admin;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Renders a dismissible per-account "could not connect" notice on the emails list screen.
 */
class Admin_Notices {

	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api      Main API instance (to enumerate accounts).
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Plugin settings (emails CPT / screen scoping).
	 * @param LoggerInterface                    $logger   PSR-3 logger.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Print a dismissible error notice for each account whose most recent fetch failed.
	 *
	 * @hooked admin_notices
	 */
	public function display(): void {

		$screen    = get_current_screen();
		$post_type = $this->settings->get_emails_cpt_underscored_20();

		// Scope to the emails list screen — the place a user manages these mailboxes.
		if ( null === $screen || $screen->post_type !== $post_type || 'edit' !== $screen->base ) {
			return;
		}

		foreach ( $this->api->get_email_accounts() as $account ) {
			if ( ! $this->is_failing( $account ) ) {
				continue;
			}

			$message = sprintf(
				/* translators: %s: the email account address. */
				__( 'bh-wp-mailboxes could not connect to the account “%s” on the last attempt. Please check the connection settings.', 'bh-wp-mailboxes' ),
				$account->get_account_email_address()
			);

			printf(
				'<div class="notice notice-error is-dismissible bh-mailboxes-auth-failure" data-account-id="%d"><p>%s</p></div>',
				(int) $account->get_post_id(),
				esc_html( $message )
			);
		}
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

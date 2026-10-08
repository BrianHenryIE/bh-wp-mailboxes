<?php
/**
 * The Demo mailbox's receive-only connection: emails are delivered to it, it cannot be fetched.
 *
 * Stands in for a push integration such as the Cloudflare worker posting to the REST ingress, or an
 * AWS SNS webhook. It deliberately does not implement `Supports_Fetching`, so the UI offers no "Check
 * now", read/unread or delete-on-server controls for this account. The Demo mailbox seeds its emails from
 * `development-plugin/demo-emails/delivered/` (see {@see \BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes\Demo_Mailbox}).
 *
 * The Demo mailbox is for people trying the plugin. Tests do not use it.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

use BrianHenryIE\WP_Mailboxes\API\Email_Connection_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

/**
 * A receive-only demo account.
 */
class Demo_Delivered_Connection implements Email_Connection_Interface {

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $mailbox_settings The Demo mailbox's settings, to resolve only its accounts.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $mailbox_settings,
	) {
	}

	/**
	 * Resolve this connection for the Demo mailbox's accounts that are configured to use it.
	 */
	public function register_hooks(): void {
		add_filter( 'bh_wp_mailboxes_connection_for_account', array( $this, 'connection' ), 10, 4 );
	}

	/**
	 * Return this connection for accounts configured to use it.
	 *
	 * @hooked bh_wp_mailboxes_connection_for_account
	 *
	 * @param mixed            $value            The connection found so far (begins as null).
	 * @param string           $plugin_slug      The plugin the asking API instance belongs to.
	 * @param string           $emails_post_type The emails post type of the asking mailbox.
	 * @param BH_Email_Account $email_account    The account a connection is wanted for.
	 */
	public function connection( mixed $value, string $plugin_slug, string $emails_post_type, BH_Email_Account $email_account ): mixed {
		if ( $this->mailbox_settings->get_plugin_slug() === $plugin_slug
			&& $this->mailbox_settings->get_emails_cpt_underscored_20() === $emails_post_type
			&& self::class === $email_account->connection_type_class ) {
			return $this;
		}

		return $value;
	}

	/**
	 * A short human-readable name for this connection type.
	 */
	public function get_friendly_name(): string {
		return 'Demo (delivered, receive only)';
	}

	/**
	 * Nothing to connect to: emails are pushed to this account.
	 */
	public function test_connection(): bool {
		return true;
	}
}

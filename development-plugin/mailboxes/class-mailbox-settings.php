<?php
/**
 * A named, parameterized BH_WP_Mailboxes_Settings_Interface for the development plugin's mailboxes.
 *
 * A reference implementation the README can link to: the three demo mailboxes (IMAP, Gmail, fixtures)
 * differ only in their CPT friendly names, so one parameterized class replaces three near-identical
 * anonymous classes. The defaults trait derives the CPT keys, CLI base, cron schedules, etc.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

/**
 * Parameterized mailbox settings.
 */
class Mailbox_Settings implements BH_WP_Mailboxes_Settings_Interface {
	use BH_WP_Mailboxes_Settings_Defaults_Trait;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_slug                      The plugin slug (namespaces the CPTs, CLI, and filters).
	 * @param string $emails_cpt_friendly_name         Display name for the emails CPT.
	 * @param string $email_accounts_cpt_friendly_name Display name for the email-accounts CPT.
	 */
	public function __construct(
		private string $plugin_slug,
		private string $emails_cpt_friendly_name,
		private string $email_accounts_cpt_friendly_name,
	) {
	}

	/**
	 * Returns the plugin slug.
	 */
	public function get_plugin_slug(): string {
		return $this->plugin_slug;
	}

	/**
	 * Returns the emails CPT friendly name.
	 */
	public function get_emails_cpt_friendly_name(): string {
		return $this->emails_cpt_friendly_name;
	}

	/**
	 * Returns the email-accounts CPT friendly name.
	 */
	public function get_email_accounts_cpt_friendly_name(): string {
		return $this->email_accounts_cpt_friendly_name;
	}
}

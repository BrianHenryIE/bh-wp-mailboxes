<?php
/**
 * The connection settings, from/body filter, and post-reconciliation action.
 *
 * @link       https://GitHub.com/BrianHenryIE/bh-wp-mailboxes
 * @since      1.0.0
 *
 * @package    brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

/**
 * @see BH_WP_Mailboxes_Settings_Defaults_Trait
 */
interface BH_WP_Mailboxes_Settings_Interface {

	/**
	 * Plugin slug is used in transient names etc.
	 */
	public function get_plugin_slug(): string;

	/**
	 * Name for the emails' custom post type, e.g. "My Plugin Emails".
	 *
	 * Trait will automatically convert this to "my-plugin-emails" and "my_plugin_emails" where appropriate,
	 * using `sanitize_title` and additionally `str_replace('-','_'...)` respectively.
	 *
	 * Should usually be one cpt per plugin. But there can be more than one mailbox per plugin.
	 * This should be hard-coded, and not derived from user input (e.g. mailbox name).
	 *
	 * Max.  length 20 characters.
	 */
	public function get_cpt_friendly_name(): string;

	/**
	 * The settings for the mailboxes to be checked.
	 *
	 * @return Mailbox_Settings_Interface[]
	 */
	public function get_configured_mailbox_settings(): array;

	/**
	 * Email attachments are stored in a subfolder of the wp-content/uploads directory. What name should be given to
	 * the folder? e.g. my-helpdesk-attachments. (use hyphenated name for WordPress convention)
	 *
	 * If this is null, no directory will be created and no attachments will be saved.
	 *
	 * Trait default: plugin-slug-email-attachments
	 */
	public function get_private_uploads_directory_name(): ?string;

	/**
	 *
	 * @return string
	 * @see BH_WP_Mailboxes_Settings_Defaults_Trait::get_cpt_dashed()
	 */
	public function get_cpt_dashed(): string;

	/**
	 * @return string
	 * @see BH_WP_Mailboxes_Settings_Defaults_Trait::get_cpt_underscored()
	 */
	public function get_cpt_underscored_20(): string;

	/**
	 * Set how often the emails should be fetched, and how often locally saved emails should be deleted.
	 * Set to null to disable (do not unset, explicitly set each entry to null, so the existing cron job will be removed).
	 *
	 * @return array{fetch_emails:string, delete_local_emails:string}
	 */
	public function get_cron_schedules(): array;
}

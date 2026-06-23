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
 * Settings interface for bh-wp-mailboxes.
 *
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
	public function get_emails_cpt_friendly_name(): string;

	/**
	 * The display name for email accounts' list.
	 */
	public function get_email_accounts_cpt_friendly_name(): string;

	/**
	 * Email attachments are stored in a subfolder of the wp-content/uploads directory. What name should be given to
	 * the folder? e.g. my-helpdesk-attachments. (use hyphenated name for WordPress convention)
	 *
	 * If this is null, no directory will be created and no attachments will be saved.
	 *
	 * Trait default: plugin-slug-email-attachments
	 *
	 * @see BH_WP_Mailboxes_Settings_Defaults_Trait::get_private_uploads_directory_name()
	 */
	public function get_private_uploads_directory_name(): ?string;

	/**
	 * The custom post type name used when registering the email post type.
	 *
	 * @see BH_WP_Mailboxes_Settings_Defaults_Trait::get_cpt_underscored(
	 * @used-by BH_Email_CPT::register_cpt()
	 *
	 * @return non-empty-lowercase-string
	 */
	public function get_emails_cpt_underscored_20(): string;

	/**
	 * The CPT name/key for saving configured email accounts.
	 *
	 * @used-by BH_Email_Account_CPT::register_cpt()
	 *
	 * @return non-empty-lowercase-string
	 */
	public function get_email_accounts_cpt_underscored_20(): string;

	/**
	 * CPT name used in script handles.
	 *
	 * @see BH_WP_Mailboxes_Settings_Defaults_Trait::get_emails_cpt_dashed()
	 */
	public function get_emails_cpt_dashed(): string;

	/**
	 * The CPT title, lowercase-dashed for enqueuing CSS/JS.
	 */
	public function get_email_accounts_cpt_dashed(): string;

	/**
	 * Set how often the emails should be fetched, and how often locally saved emails should be deleted.
	 * Return an empty array or omit keys to disable specific jobs.
	 *
	 * @return array<string, string>
	 */
	public function get_cron_schedules(): array;

	/**
	 * The base namespace for this instance's WP-CLI commands, e.g. `wp {cli_base} accounts list`.
	 *
	 * Return `null` to disable registering CLI commands for this instance.
	 *
	 * @see BH_WP_Mailboxes_Settings_Defaults_Trait::get_cli_base() Defaults to the plugin slug.
	 */
	public function get_cli_base(): ?string;
}

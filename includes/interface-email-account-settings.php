<?php
/**
 * The connection settings, from/body filter, and post-reconciliation action.
 *
 * @see \BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait
 *
 * @package    brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

interface Email_Account_Settings_Interface {

	/**
	 * Allow deactivating an email account without deleting the settings, so it is no longer automatically checked.
	 */
	public function is_active(): bool;

	/**
	 * The email address.
	 *
	 * You'd think this is the same as the credentials username, but it is not always.
	 */
	public function get_account_email_address(): string;

	/**
	 * The friendly account name to display.
	 */
	public function get_account_display_friendly_name(): string;

	/**
	 * Should the email be deleted after it is reconciled.
	 *
	 * @return string nothing|mark_read|delete
	 */
	public function after_download_remote_email_action(): string;

	/**
	 * Regex to filter email from address for further matching.
	 *
	 * Return null to match all addresses. (e.g. when emails are forwarded and do not preserve the real sender).
	 *
	 * @return ?string The pertinent email address.
	 */
	public function get_from_email_regex(): ?string;

	/**
	 * Set an identifier filter emails. i.e. if the email does not contain this, it is not relevant, so
	 * continue onto the next email in the inbox. Checks the body text of the email.
	 *
	 * Return null to match all.
	 *
	 * @return string|null
	 */
	public function get_body_identifier_regex(): ?string;

	/**
	 * Number of days to keep the emails. i.e. number of days after which the emails should be deleted.
	 *
	 * Set to 0 or null to disable.
	 *
	 * @return int|null
	 */
	public function get_delete_emails_days(): ?int;
}

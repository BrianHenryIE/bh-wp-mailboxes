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

interface Mailbox_Settings_Interface {

	// Allow deactivating a mailbox without deleting the settings, so it is no longer automatically checked.
	// public function is_active(): bool

	/**
	 * The friendly account name to display.
	 *
	 * This will be used to create a category for the CPT.
	 *
	 * @return string
	 */
	public function get_account_unique_friendly_name(): string;


	public function get_credentials(): Account_Credentials_Interface;

	/**
	 * Should the email be deleted after it is reconciled.
	 *
	 * @return string nothing|mark_read|delete
	 */
	public function after_download_email_action(): string;

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
	public function get_identifier_regex(): ?string;

	/**
	 * Number of days to keep the emails. i.e. number of days after which the emails should be deleted.
	 *
	 * Set to 0 or null to disable.
	 *
	 * @return int|null
	 */
	public function get_delete_emails_days(): ?int;

	/**
	 * Whether this mailbox supports marking emails as read/unread on the remote server.
	 */
	public function can_mark_read(): bool;

	/**
	 * Whether this mailbox supports deleting emails on the remote server.
	 */
	public function can_delete_on_server(): bool;
}

<?php
/**
 * Convenience methods for Mailboxes_Settings_Interface.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

use BrianHenryIE\WP_Private_Uploads\Private_Uploads;

/**
 * Default implementations for BH_WP_Mailboxes_Settings_Interface.
 *
 * @see BH_WP_Mailboxes_Settings_Interface
 * @phpstan-require-implements BH_WP_Mailboxes_Settings_Interface
 */
trait BH_WP_Mailboxes_Settings_Defaults_Trait {

	/**
	 * Returns the plugin slug.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_plugin_slug()
	 */
	abstract public function get_plugin_slug(): string;

	/**
	 * Returns the CPT friendly/display name for saved emails.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_emails_cpt_friendly_name()
	 */
	abstract public function get_emails_cpt_friendly_name(): string;

	/**
	 * Returns the CPT friendly/display name for email accounts.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_email_accounts_cpt_friendly_name()
	 */
	abstract public function get_email_accounts_cpt_friendly_name(): string;

	/**
	 * Returns the emails CPT name in dashed format.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_emails_cpt_dashed()
	 */
	public function get_emails_cpt_dashed(): string {
		return sanitize_title( $this->get_emails_cpt_friendly_name() );
	}

	/**
	 * Used in JS handles.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_email_accounts_cpt_dashed()
	 */
	public function get_email_accounts_cpt_dashed(): string {
		return sanitize_title( $this->get_email_accounts_cpt_friendly_name() );
	}

	/**
	 * CPT name emails are saved as.
	 *
	 * Return a sanitized custom post type name with a max length of 20.
	 *
	 * The custom post type key "Must not exceed 20 characters" and conventionally uses underscores for separators.
	 *
	 * @see https://developer.wordpress.org/reference/functions/register_post_type/
	 *
	 * @return non-empty-lowercase-string
	 */
	public function get_emails_cpt_underscored_20(): string {
		$cpt_underscored = substr( str_replace( '-', '_', $this->get_emails_cpt_dashed() ), 0, 20 );
		/**
		 * `sanitize_title()` returns a lowercase string and the friendly name is a required, non-empty setting.
		 *
		 * @var non-empty-lowercase-string $cpt_underscored
		 */
		return $cpt_underscored;
	}

	/**
	 * The custom post type key/name (not title) for configured email accounts.
	 *
	 * @return non-empty-lowercase-string
	 */
	public function get_email_accounts_cpt_underscored_20(): string {
		$cpt_underscored = substr( str_replace( '-', '_', $this->get_email_accounts_cpt_dashed() ), 0, 20 );
		/**
		 * `sanitize_title()` returns a lowercase string and the friendly name is a required, non-empty setting.
		 *
		 * @var non-empty-lowercase-string $cpt_underscored
		 */
		return $cpt_underscored;
	}

	/**
	 * Returns the private uploads subdirectory name – where attachments are saved.
	 *
	 * @see \BrianHenryIE\WP_Private_Uploads\API\Settings_Interface
	 */
	public function get_private_uploads_directory_name(): ?string {
		return class_exists( Private_Uploads::class )
			? $this->get_plugin_slug() . '-email-attachments'
			: null;
	}

	/**
	 * Returns the default cron schedules for fetching and deleting emails.
	 *
	 * @see wp_get_schedules()
	 *
	 * @return array<string, string>
	 */
	public function get_cron_schedules(): array {
		return array(
			'fetch_emails'        => 'hourly',
			'delete_local_emails' => 'daily',
		);
	}

	/**
	 * The base namespace for WP-CLI commands. Defaults to the plugin slug.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_cli_base()
	 */
	public function get_cli_base(): ?string {
		return $this->get_plugin_slug();
	}
}

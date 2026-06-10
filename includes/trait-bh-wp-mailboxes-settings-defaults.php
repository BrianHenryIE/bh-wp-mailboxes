<?php
/**
 * Convenience methods for Mailboxes_Settings_Interface.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

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
	 * Returns the CPT friendly name.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_cpt_friendly_name()
	 */
	abstract public function get_cpt_friendly_name(): string;

	/**
	 * Returns the CPT name in dashed format.
	 */
	public function get_cpt_dashed(): string {
		return sanitize_title( $this->get_cpt_friendly_name() );
	}

	/**
	 * Return a sanitized custom post type name with a max length of 20.
	 *
	 * The custom post type key "Must not exceed 20 characters" and conventionally uses underscores for separators.
	 *
	 * @see https://developer.wordpress.org/reference/functions/register_post_type/
	 *
	 * @return string
	 */
	public function get_cpt_underscored_20(): string {
		$cpt_underscored = str_replace( '-', '_', $this->get_cpt_dashed() );
		return substr( $cpt_underscored, 0, 20 );
	}

	/**
	 * Returns the private uploads subdirectory name.
	 *
	 * @see \BrianHenryIE\WP_Private_Uploads\API\Settings_Interface
	 */
	public function get_private_uploads_directory_name(): ?string {
		return $this->get_plugin_slug() . '-email-attachments';
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
}

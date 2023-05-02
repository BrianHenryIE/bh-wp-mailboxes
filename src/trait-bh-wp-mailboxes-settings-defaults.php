<?php
/**
 * Convenience methods for Mailboxes_Settings_Interface.
 */

namespace BrianHenryIE\WP_Mailboxes;

/**
 * @see BH_WP_Mailboxes_Settings_Interface
 */
trait BH_WP_Mailboxes_Settings_Defaults_Trait {

	/**
	 * @see BH_WP_Mailboxes_Settings_Interface::get_plugin_slug()
	 */
	abstract function get_plugin_slug(): string;

	/**
	 * @see BH_WP_Mailboxes_Settings_Interface::get_cpt_friendly_name()
	 */
	abstract function get_cpt_friendly_name(): string;

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
	 * @see \BrianHenryIE\WP_Private_Uploads\API\Settings_Interface
	 */
	public function get_private_uploads_directory_name(): ?string {
		return $this->get_plugin_slug() . '-email-attachments';
	}

	/**
	 * @see wp_get_schedules()
	 *
	 * @return array{fetch_emails:string, delete_local_emails:string}
	 */
	public function get_cron_schedules(): array {
		return array(
			'fetch_emails'        => 'hourly',
			'delete_local_emails' => 'daily',
		);
	}

}

<?php
/**
 * The REST namespace a mailbox's routes live under.
 *
 * Kept apart from the controllers so admin screens can compute route URLs without loading
 * `WP_REST_Controller`, which WordPress only loads on REST requests.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\REST;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

/**
 * `{rest_namespace|plugin_slug}/v2`.
 */
final class REST_Namespace {

	/**
	 * The REST namespace for a mailbox: the consumer's REST namespace (shared with the ingress route) or,
	 * when none is configured, the plugin slug; always versioned `/v2`.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The mailbox settings.
	 *
	 * @return non-falsy-string
	 */
	public static function for_mailbox( BH_WP_Mailboxes_Settings_Interface $settings ): string {
		$rest_namespace = $settings->get_rest_namespace();

		return ( empty( $rest_namespace ) ? $settings->get_plugin_slug() : $rest_namespace ) . '/v2';
	}

	/**
	 * The absolute URL of a mailbox's REST namespace, for scripts to build route URLs from.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The mailbox settings.
	 */
	public static function url( BH_WP_Mailboxes_Settings_Interface $settings ): string {
		return esc_url_raw( rest_url( self::for_mailbox( $settings ) ) );
	}
}

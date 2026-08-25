<?php
/**
 * The two empty demo mailboxes ("Mailbox One" and "Mailbox Two") configured from the dev settings page.
 *
 * Unlike the Fixtures and E2E mailboxes, these are registered with no accounts; the settings page
 * creates accounts in them (from `.env.secret`, the IMAP credentials form, or Gmail credentials).
 * REST can be enabled per mailbox via a wp_option, giving each mailbox its own REST namespace.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;

/**
 * Registry and option-store for the two settings-page-configured demo mailboxes.
 */
class Dev_Mailboxes {

	public const MAILBOX_ONE = 'mailbox-one';
	public const MAILBOX_TWO = 'mailbox-two';

	/**
	 * Option name prefix for the per-mailbox REST-enabled flag; the mailbox slug is appended.
	 */
	public const OPTION_REST_ENABLED_PREFIX = 'bh_wp_mailboxes_dev_rest_enabled_';

	/**
	 * The CPT friendly names for each mailbox, keyed by mailbox slug.
	 *
	 * @return array<string, array{emails: string, accounts: string}>
	 */
	public static function get_names(): array {
		return array(
			self::MAILBOX_ONE => array(
				'emails'   => 'Mailbox One Email',
				'accounts' => 'Mailbox One Accounts',
			),
			self::MAILBOX_TWO => array(
				'emails'   => 'Mailbox Two Email',
				'accounts' => 'Mailbox Two Accounts',
			),
		);
	}

	/**
	 * The two mailbox slugs.
	 *
	 * @return string[]
	 */
	public static function get_slugs(): array {
		return array_keys( self::get_names() );
	}

	/**
	 * Whether the given string is one of the two mailbox slugs.
	 *
	 * @param string $slug The candidate mailbox slug.
	 */
	public static function is_valid_slug( string $slug ): bool {
		return in_array( $slug, self::get_slugs(), true );
	}

	/**
	 * The wp_option name storing whether REST is enabled for the mailbox.
	 *
	 * @param string $slug The mailbox slug.
	 */
	public static function get_rest_enabled_option_name( string $slug ): string {
		return self::OPTION_REST_ENABLED_PREFIX . str_replace( '-', '_', $slug );
	}

	/**
	 * Whether REST is enabled for the mailbox (settings-page checkbox, stored as a wp_option).
	 *
	 * @param string $slug The mailbox slug.
	 */
	public static function is_rest_enabled( string $slug ): bool {
		return (bool) get_option( self::get_rest_enabled_option_name( $slug ), false );
	}

	/**
	 * Persist the per-mailbox REST-enabled flag.
	 *
	 * @param string $slug    The mailbox slug.
	 * @param bool   $enabled Whether REST should be enabled.
	 */
	public static function set_rest_enabled( string $slug, bool $enabled ): void {
		update_option( self::get_rest_enabled_option_name( $slug ), $enabled );
	}

	/**
	 * Build the mailbox's settings; the mailbox slug doubles as its REST namespace when REST is enabled.
	 *
	 * @param string $slug The mailbox slug.
	 */
	public static function make_settings( string $slug ): Mailbox_Settings {
		$names = self::get_names()[ $slug ];

		return new Mailbox_Settings(
			'development-plugin',
			$names['emails'],
			$names['accounts'],
			self::is_rest_enabled( $slug ) ? $slug : null,
		);
	}

	/**
	 * Resolve the registered mailbox API instance for a mailbox slug, or null before `plugins_loaded`.
	 *
	 * @param string $slug The mailbox slug.
	 */
	public static function get_api( string $slug ): ?API {

		$emails_cpt = self::make_settings( $slug )->get_emails_cpt_underscored_20();

		$mailboxes = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), 'development-plugin' );

		foreach ( (array) $mailboxes as $api ) {
			if ( $api instanceof API
				&& $api->get_settings()->get_emails_cpt_underscored_20() === $emails_cpt ) {
				return $api;
			}
		}

		return null;
	}
}

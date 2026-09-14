<?php
/**
 * Grants editors access to the e2e mailbox, through the library's `bh_wp_mailboxes_required_capability`
 * filter, at a level chosen on the settings page (or set by the dev REST `/editor-access` route).
 *
 * The library maps every mailbox capability to `manage_options` unless a consumer lowers it; this is
 * that consumer for the e2e mailbox, so the Playwright "editor" project can prove the admin screens
 * show only the controls a user may use. Nothing is written to roles: `edit_pages` (which editors have
 * and subscribers do not) is returned as the required capability.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin;

use BrianHenryIE\WP_Mailboxes\WP_Includes\Mailbox_Capabilities;

/**
 * The consumer-side half of the library's capability model, for the e2e mailbox.
 */
class Editor_Access {

	public const OPTION = 'bh_wp_mailboxes_dev_editor_access';

	/**
	 * The access levels: none, read (list and open emails, no actions), edit (act on emails, not on
	 * accounts), manage (everything, including the accounts table and "Check now").
	 */
	public const LEVELS = array( '', 'read', 'edit', 'manage' );

	/**
	 * The capability editors have and subscribers do not.
	 */
	protected const GRANTED = 'edit_pages';

	/**
	 * Constructor.
	 *
	 * @param string $emails_cpt   The e2e mailbox's emails post type.
	 * @param string $accounts_cpt The e2e mailbox's accounts post type.
	 */
	public function __construct(
		protected string $emails_cpt,
		protected string $accounts_cpt,
	) {
	}

	/**
	 * Register the library filter.
	 */
	public function register_hooks(): void {
		add_filter( Mailbox_Capabilities::REQUIRED_CAPABILITY_FILTER, $this->required_capability( ... ), 10, 3 );
	}

	/**
	 * The current level ('' when editors have no access).
	 */
	public static function get_level(): string {
		$level = get_option( self::OPTION, '' );

		return is_string( $level ) && in_array( $level, self::LEVELS, true ) ? $level : '';
	}

	/**
	 * Set the level.
	 *
	 * @param string $level One of {@see self::LEVELS}.
	 */
	public static function set_level( string $level ): bool {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			return false;
		}
		update_option( self::OPTION, $level );

		return true;
	}

	/**
	 * Lower the required capability for the e2e mailbox according to the level.
	 *
	 * @hooked bh_wp_mailboxes_required_capability
	 *
	 * @param string $required   The base capability the library requires (`manage_options`).
	 * @param string $capability The mailbox capability being checked, e.g. `edit_post`, `edit_e2e_emails`, `manage_e2e_accounts`.
	 * @param string $post_type  The mailbox post type the capability belongs to.
	 */
	public function required_capability( string $required, string $capability, string $post_type ): string {
		if ( ! in_array( $post_type, array( $this->emails_cpt, $this->accounts_cpt ), true ) ) {
			return $required;
		}

		switch ( self::get_level() ) {
			case 'manage':
				return self::GRANTED;
			case 'edit':
				// Everything on emails; nothing on accounts.
				return $post_type === $this->emails_cpt ? self::GRANTED : $required;
			case 'read':
				return $post_type === $this->emails_cpt && in_array( $capability, $this->read_capabilities(), true ) ? self::GRANTED : $required;
			default:
				return $required;
		}
	}

	/**
	 * The email capabilities needed to list and open emails without acting on them: the per-post read
	 * check, and the primitives the list table needs to show every email.
	 *
	 * @return string[]
	 */
	protected function read_capabilities(): array {
		$capabilities     = array( 'read_post' );
		$post_type_object = get_post_type_object( $this->emails_cpt );
		if ( is_null( $post_type_object ) ) {
			return $capabilities;
		}

		foreach ( array( 'edit_posts', 'edit_others_posts', 'read_private_posts' ) as $name ) {
			$capability = $post_type_object->cap->$name ?? null;
			if ( is_string( $capability ) ) {
				$capabilities[] = $capability;
			}
		}

		return $capabilities;
	}
}

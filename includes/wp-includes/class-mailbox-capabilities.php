<?php
/**
 * Mailbox-scoped capabilities: the single place that decides who may read, act on and manage a mailbox.
 *
 * Each library instance (one per consuming plugin) registers its own emails and accounts post types with
 * `capability_type` set to the post type name, so WordPress mints a distinct capability set per mailbox
 * (`edit_{emails_cpt}`, `edit_others_{emails_cpt}s`, `delete_{accounts_cpt}`, …) plus per-post meta
 * capabilities. Nothing is written to roles: this class's {@see self::map_meta_cap()} filter maps every
 * one of those capabilities to a base capability — `manage_options` unless a consumer lowers it with the
 * `bh_wp_mailboxes_required_capability` filter, which receives the post type so the grant can be scoped
 * to one mailbox.
 *
 * Enforcement happens at the transport boundary (REST permission callbacks, admin screens). Cron and
 * WP-CLI run without a user and never consult this class.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use WP_Post;
use WP_Post_Type;

/**
 * Named capability checks for one mailbox, and the `map_meta_cap` mapping behind them.
 */
class Mailbox_Capabilities {

	/**
	 * Filter name: lets a consumer decide which base capability a mailbox capability requires.
	 */
	const REQUIRED_CAPABILITY_FILTER = 'bh_wp_mailboxes_required_capability';

	/**
	 * The base capability every mailbox capability maps to unless filtered.
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param BH_WP_Mailboxes_Settings_Interface $settings Provides this mailbox's post type names.
	 */
	public function __construct(
		protected BH_WP_Mailboxes_Settings_Interface $settings,
	) {
	}

	/**
	 * May the current user read this email (view it in the admin, fetch its data)?
	 *
	 * @param int $email_post_id The email post.
	 */
	public function current_user_can_read_email( int $email_post_id ): bool {
		return $this->current_user_can_meta( $this->settings->get_emails_cpt_underscored_20(), 'read_post', $email_post_id );
	}

	/**
	 * May the current user act on this email (remote actions, local status, log notes)?
	 *
	 * @param int $email_post_id The email post.
	 */
	public function current_user_can_edit_email( int $email_post_id ): bool {
		return $this->current_user_can_meta( $this->settings->get_emails_cpt_underscored_20(), 'edit_post', $email_post_id );
	}

	/**
	 * May the current user trash or delete this email locally?
	 *
	 * @param int $email_post_id The email post.
	 */
	public function current_user_can_delete_email( int $email_post_id ): bool {
		return $this->current_user_can_meta( $this->settings->get_emails_cpt_underscored_20(), 'delete_post', $email_post_id );
	}

	/**
	 * May the current user list this mailbox's emails?
	 */
	public function current_user_can_list_emails(): bool {
		$capability = $this->get_capability( $this->settings->get_emails_cpt_underscored_20(), 'edit_posts' );

		return ! is_null( $capability ) && current_user_can( $capability );
	}

	/**
	 * The (mailbox-scoped) capability for listing emails, e.g. `edit_my_plugin_emails`, for code that
	 * needs a capability name rather than a check (the auth-failure admin notice); null when the emails
	 * post type is not registered.
	 */
	public function get_list_emails_capability(): ?string {
		return $this->get_capability( $this->settings->get_emails_cpt_underscored_20(), 'edit_posts' );
	}

	/**
	 * May the current user create emails in this mailbox (the REST ingress)?
	 */
	public function current_user_can_create_email(): bool {
		$capability = $this->get_capability( $this->settings->get_emails_cpt_underscored_20(), 'create_posts' );

		return ! is_null( $capability ) && current_user_can( $capability );
	}

	/**
	 * May the current user manage this mailbox's accounts: add, edit, test, check, enable/disable,
	 * delete an account, and "check all"? One umbrella capability for everything credential-adjacent.
	 */
	public function current_user_can_manage_email_accounts(): bool {
		return current_user_can( $this->get_manage_email_accounts_capability() );
	}

	/**
	 * The (mailbox-scoped) capability for managing accounts, e.g. `manage_my_plugin_accounts`.
	 */
	public function get_manage_email_accounts_capability(): string {
		return 'manage_' . $this->settings->get_email_accounts_cpt_underscored_20();
	}

	/**
	 * Map this mailbox's capabilities to the base capability the consumer requires.
	 *
	 * WordPress resolves a meta capability (`edit_post` on an email) to primitive ones
	 * (`edit_{emails_cpt}s`, `edit_others_{emails_cpt}s`, …); this replaces any primitive that belongs
	 * to this mailbox with the filtered base capability. A meta capability on a post of this mailbox is
	 * mapped as a whole, so a non-public status can never fall through to plain `read`.
	 *
	 * @hooked map_meta_cap
	 *
	 * @param string[]     $caps    The primitive capabilities WordPress resolved so far.
	 * @param string       $cap     The capability being checked.
	 * @param int          $user_id The user being checked.
	 * @param array<mixed> $args    Further arguments; a post id for meta capabilities.
	 *
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		$post_id = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : null;

		// A meta capability on one of this mailbox's posts: the whole check becomes the mapped capability.
		if ( in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) && ! is_null( $post_id ) ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && ! is_null( $this->post_type_for_capability_type( $post->post_type ) ) ) {
				return array( $this->required_capability( $cap, $post->post_type, $post_id ) );
			}
		}

		$mapped = array();
		foreach ( $caps as $primitive ) {
			$post_type = $this->post_type_for_capability( $primitive );
			$mapped[]  = is_null( $post_type ) ? $primitive : $this->required_capability( $primitive, $post_type, $post_id );
		}

		return $mapped;
	}

	/**
	 * The base capability a mailbox capability requires, after the consumer's filter.
	 *
	 * @param string $capability The mailbox capability being checked, e.g. `edit_my_plugin_emails`.
	 * @param string $post_type  The mailbox's post type the capability belongs to.
	 * @param ?int   $post_id    The post, for meta capabilities.
	 */
	protected function required_capability( string $capability, string $post_type, ?int $post_id ): string {
		/**
		 * Filters the base capability a mailbox capability requires.
		 *
		 * Defaults to `manage_options`. A consumer can lower it for its own mailbox only, because the
		 * post type identifies the mailbox, e.g. grant shop managers access to a payments inbox.
		 *
		 * @param string $required   The base capability, default `manage_options`.
		 * @param string $capability The mailbox capability being checked, e.g. `edit_{emails_cpt}` or `manage_{accounts_cpt}`.
		 * @param string $post_type  The emails or accounts post type the capability belongs to (which mailbox).
		 * @param ?int   $post_id    The post being acted on, for per-post capabilities; null otherwise.
		 */
		$required = apply_filters( self::REQUIRED_CAPABILITY_FILTER, self::DEFAULT_REQUIRED_CAPABILITY, $capability, $post_type, $post_id );

		return '' !== $required ? $required : 'do_not_allow';
	}

	/**
	 * Which of this mailbox's post types a primitive capability belongs to, or null when it is not ours.
	 *
	 * @param string $capability A primitive capability, e.g. `edit_others_my_plugin_emails`.
	 */
	protected function post_type_for_capability( string $capability ): ?string {
		if ( $capability === $this->get_manage_email_accounts_capability() ) {
			return $this->settings->get_email_accounts_cpt_underscored_20();
		}

		foreach ( array( $this->settings->get_emails_cpt_underscored_20(), $this->settings->get_email_accounts_cpt_underscored_20() ) as $post_type ) {
			if ( in_array( $capability, $this->get_post_type_capabilities( $post_type ), true ) ) {
				return $post_type;
			}
		}

		return null;
	}

	/**
	 * The post type name when it is one of this mailbox's, else null.
	 *
	 * @param string $post_type A post type name.
	 */
	protected function post_type_for_capability_type( string $post_type ): ?string {
		return in_array( $post_type, array( $this->settings->get_emails_cpt_underscored_20(), $this->settings->get_email_accounts_cpt_underscored_20() ), true )
			? $post_type
			: null;
	}

	/**
	 * The primitive capabilities WordPress minted for a post type (excluding the generic `read`).
	 *
	 * @param string $post_type The post type.
	 *
	 * @return string[]
	 */
	protected function get_post_type_capabilities( string $post_type ): array {
		$post_type_object = get_post_type_object( $post_type );
		if ( ! ( $post_type_object instanceof WP_Post_Type ) ) {
			return array();
		}

		$capabilities = array();
		foreach ( (array) $post_type_object->cap as $key => $capability ) {
			if ( 'read' !== $key && is_string( $capability ) && 'read' !== $capability ) {
				$capabilities[] = $capability;
			}
		}

		return array_values( array_unique( $capabilities ) );
	}

	/**
	 * A named capability of a post type, e.g. `edit_posts` → `edit_my_plugin_emails`; null when the post
	 * type is not registered (fail closed).
	 *
	 * @param string $post_type The post type.
	 * @param string $name      The capability's key on the post type's `cap` object.
	 */
	protected function get_capability( string $post_type, string $name ): ?string {
		$post_type_object = get_post_type_object( $post_type );
		$capability       = $post_type_object?->cap->$name ?? null;

		return is_string( $capability ) ? $capability : null;
	}

	/**
	 * A per-post check that fails closed when the post is not of the expected type.
	 *
	 * @param string $post_type The post type the post must have.
	 * @param string $meta_cap  `read_post`, `edit_post` or `delete_post`.
	 * @param int    $post_id   The post.
	 */
	protected function current_user_can_meta( string $post_type, string $meta_cap, int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) || $post->post_type !== $post_type ) {
			return false;
		}

		$capability = $this->get_capability( $post_type, $meta_cap );

		return ! is_null( $capability ) && current_user_can( $capability, $post_id );
	}
}

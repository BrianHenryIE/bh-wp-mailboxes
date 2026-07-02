<?php
/**
 * A fixtures-backed connection dedicated to end-to-end tests.
 *
 * Identical behaviour to {@see Mock_Mailbox_Fixtures_Connection}, but a distinct class so the
 * `bh_wp_mailboxes_connection_for_account` filter resolves it only for the e2e mailbox's accounts, and a
 * distinct meta-key prefix so its per-user read/unread/deleted state never touches the human-facing
 * "Fixtures" demo mailbox. This is what lets Playwright arrange/assert in its own mailbox in isolation.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

/**
 * The e2e-only mock mailbox connection.
 */
class Mock_Mailbox_E2E_Connection extends Mock_Mailbox_Fixtures_Connection {

	/**
	 * The emails CPT of the e2e mailbox (derived from the "E2E Email" friendly name). Shared so the Menu can
	 * exclude it and the dev REST can target it.
	 *
	 * @var string
	 */
	public const EMAILS_CPT = 'e2e_email';

	/**
	 * The accounts CPT of the e2e mailbox (derived from the "E2E Accounts" friendly name).
	 *
	 * @var string
	 */
	public const ACCOUNTS_CPT = 'e2e_accounts';

	/**
	 * Namespace this connection's per-user state so it is fully separate from the demo fixtures mailbox.
	 *
	 * @var string
	 */
	public const META_KEY_PREFIX = '_mock_mailbox_e2e_connection_';

	/**
	 * A short human-readable name for this connection type.
	 */
	public function get_friendly_name(): string {
		return 'E2E Fixtures';
	}
}

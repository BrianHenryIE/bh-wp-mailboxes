<?php
/**
 * The Demo mailbox's fetching connection: a fixtures connection reading the demo's own `.eml` files.
 *
 * Behaves like an IMAP or Gmail account (it supports fetching, read/unread and delete-on-server), but
 * its "server" is `development-plugin/demo-emails/fetched/`. Read/unread/deleted state is kept per user,
 * under its own meta-key prefix so it never mixes with the Fixtures or E2E mailboxes.
 *
 * The Demo mailbox is for people trying the plugin. Tests do not use it.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

declare(strict_types=1);

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Connections;

/**
 * A fetchable demo account backed by the demo `.eml` files.
 */
class Demo_Fetching_Connection extends Mock_Mailbox_Fixtures_Connection {

	/**
	 * Absolute path to the directory of `.eml` files this account "fetches".
	 *
	 * @var string
	 */
	protected string $fixtures_directory = __DIR__ . '/../demo-emails/fetched';

	/**
	 * Namespace this connection's per-user state so it is separate from the other mock mailboxes.
	 *
	 * @var string
	 */
	public const META_KEY_PREFIX = '_demo_fetching_connection_';

	/**
	 * A short human-readable name for this connection type.
	 */
	public function get_friendly_name(): string {
		return 'Demo (fetches example emails)';
	}
}

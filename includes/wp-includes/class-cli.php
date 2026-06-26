<?php
/**
 * WP-CLI commands for the plugin.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp {$plugin_slug} accounts list`
 */
class CLI {
	use LoggerAwareTrait;

	/**
	 * The CLI bases under which the `mailboxes list` command has already been registered.
	 *
	 * The command spans every registered mailbox, so it only needs registering once per base; this
	 * guards against a duplicate-command error when several instances share a CLI base.
	 *
	 * @var array<string,true>
	 */
	protected static array $mailboxes_command_registered = array();

	/**
	 * Constructor.
	 *
	 * @param API_Interface                      $api      The main API class.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The plugin configuration.
	 * @param LoggerInterface                    $logger   A logger for issues in this class.
	 */
	public function __construct(
		protected API_Interface $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		LoggerInterface $logger
	) {
		$this->logger = $logger;
	}

	/**
	 * Register the WP-CLI commands.
	 *
	 * Use `null` for the CLI base to disable registering commands for this instance.
	 *
	 * @see BH_WP_Mailboxes_Settings_Interface::get_cli_base()
	 */
	public function register_commands(): void {

		$cli_base = $this->settings->get_cli_base();

		if ( is_null( $cli_base ) ) {
			return;
		}

		try {
			WP_CLI::add_command( "{$cli_base} accounts list", array( $this, 'list_accounts' ) );

			// The `mailboxes list` command spans every registered mailbox (read from the filter), so it
			// only needs registering once per CLI base, even when several instances share that base.
			if ( ! isset( self::$mailboxes_command_registered[ $cli_base ] ) ) {
				WP_CLI::add_command( "{$cli_base} mailboxes list", array( $this, 'list_mailboxes' ) );
				self::$mailboxes_command_registered[ $cli_base ] = true;
			}
		} catch ( Exception $e ) {
			$this->logger->error(
				'Failed to register WP CLI commands: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
		}
	}

	/**
	 * List the configured mailboxes.
	 *
	 * A mailbox is one configured instance of the library — an emails post type and its accounts post
	 * type — and can contain many email accounts. Instances register themselves via the
	 * `bh_wp_mailboxes_registered_mailboxes` filter.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   # List configured mailboxes.
	 *   $ wp plugin-slug mailboxes list
	 *   +--------------------+--------------------+----------------------+------------+----------+
	 *   | slug               | emails_cpt         | accounts_cpt         | name       | accounts |
	 *   +--------------------+--------------------+----------------------+------------+----------+
	 *   | development-plugin | imap_email_env     | imap_accounts_env    | IMAP Email | 1        |
	 *   +--------------------+--------------------+----------------------+------------+----------+
	 *
	 * @param string[]             $_args      The unlabelled command line arguments.
	 * @param array<string,string> $assoc_args The labelled command line arguments.
	 */
	public function list_mailboxes( array $_args, array $assoc_args ): void {

		$fields = array( 'emails', 'emails_cpt', 'accounts', 'accounts_cpt', 'accounts_count' );

		$plugin_slug = dirname( plugin_basename( __DIR__ ), 2 );

		$mailboxes = apply_filters( 'bh_wp_mailboxes_registered_mailboxes', array(), $plugin_slug );

		$items = array();
		foreach ( (array) $mailboxes as $mailbox ) {

			if ( ! ( $mailbox instanceof API_Interface ) ) {
				// TODO: Log.
				continue;
			}

			$settings = $mailbox->get_settings();
			$api      = $mailbox;

			$items[] = array(
				'emails'         => $settings->get_emails_cpt_friendly_name(),
				'emails_cpt'     => $settings->get_emails_cpt_underscored_20(),
				'accounts'       => $settings->get_email_accounts_cpt_friendly_name(),
				'accounts_cpt'   => $settings->get_email_accounts_cpt_underscored_20(),
				'accounts_count' => count( $api->get_email_accounts() ),
			);
		}

		$format = $assoc_args['format'] ?? 'table';

		Utils\format_items( $format, $items, $fields );
	}

	/**
	 * List the configured email accounts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   # List configured accounts.
	 *   $ wp plugin-slug accounts list
	 *   +----+----------------------+--------------+------------+--------+----------------------+
	 *   | id | email                | name         | connection | active | last_checked         |
	 *   +----+----------------------+--------------+------------+--------+----------------------+
	 *   | 12 | you@example.com      | You          | Gmail      | yes    | 2026-06-21T10:00:00Z |
	 *   +----+----------------------+--------------+------------+--------+----------------------+
	 *
	 * @param string[]             $_args      The unlabelled command line arguments.
	 * @param array<string,string> $assoc_args The labelled command line arguments.
	 */
	public function list_accounts( array $_args, array $assoc_args ): void {

		$fields = array( 'id', 'email', 'name', 'connection', 'active', 'last_checked' );

		$items = array();
		foreach ( $this->api->get_email_accounts() as $account ) {
			$connection = $this->api->get_connection_for_email_account( $account );
			$items[]    = array(
				'id'           => $account->get_post_id(),
				'email'        => $account->email_address,
				'name'         => $account->display_name,
				'connection'   => $connection?->get_friendly_name() ?? $this->short_connection_name( $account->connection_type_class ),
				'active'       => $account->is_active() ? 'yes' : 'no',
				'last_checked' => $account->last_checked_time?->format( 'c' ) ?? '',
			);
		}

		$format = $assoc_args['format'] ?? 'table';

		Utils\format_items( $format, $items, $fields );
	}

	/**
	 * The unqualified class name of a connection type, for display.
	 *
	 * @param string $connection_type_class The fully-qualified connection/credentials class name.
	 */
	protected function short_connection_name( string $connection_type_class ): string {
		$position = strrpos( $connection_type_class, '\\' );
		return false === $position ? $connection_type_class : substr( $connection_type_class, $position + 1 );
	}
}

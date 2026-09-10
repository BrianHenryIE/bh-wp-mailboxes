<?php
/**
 * WP-CLI command to refresh a Gmail account's OAuth access token.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_CLI;
use WP_CLI\ExitException;

/**
 * `wp {$plugin_slug} gmail refresh-access-token --account=you@example.com`
 */
class Gmail_CLI {
	use LoggerAwareTrait;

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
			WP_CLI::add_command( "{$cli_base} gmail refresh-access-token", array( $this, 'refresh_access_token' ) );
		} catch ( Exception $e ) {
			$this->logger->error(
				'Failed to register WP CLI commands: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
		}
	}

	/**
	 * Use a Gmail account's stored refresh token to mint a fresh access token.
	 *
	 * The new token is saved to the account's credentials in the credentials store (the Secrets API).
	 *
	 * ## OPTIONS
	 *
	 * --account=<email>
	 * : The email address of the Gmail account to refresh.
	 *
	 * ## EXAMPLES
	 *
	 *   # Refresh the access token for a Gmail account.
	 *   $ wp plugin-slug gmail refresh-access-token --account=you@example.com
	 *   Success: Refreshed the Gmail access token for you@example.com.
	 *
	 * @param string[]             $_args      The unlabelled command line arguments.
	 * @param array<string,string> $assoc_args The labelled command line arguments.
	 *
	 * @throws ExitException On `WP_CLI::error()`.
	 */
	public function refresh_access_token( array $_args, array $assoc_args ): void {

		$account_email = $assoc_args['account'] ?? '';
		if ( '' === $account_email ) {
			WP_CLI::error( 'The --account=<email> option is required.' );
			return; // WP_CLI::error() halts; return keeps the type-checker happy.
		}

		$account = null;
		foreach ( $this->api->get_email_accounts() as $email_account ) {
			if ( $email_account->email_address === $account_email ) {
				$account = $email_account;
				break;
			}
		}

		if ( is_null( $account ) ) {
			WP_CLI::error( 'No email account found for ' . $account_email . '.' );
			return;
		}

		$credentials = $this->api->get_account_credentials( $account );

		if ( ! ( $credentials instanceof Google_API_Credentials_Interface ) ) {
			WP_CLI::error( 'No Gmail API credentials found for ' . $account_email . '.' );
			return;
		}

		try {
			$connection = $this->make_connection( $account );
			$connection->set_credentials( $credentials );
			$access_token = $connection->refresh_access_token();
		} catch ( Throwable $t ) {
			WP_CLI::error( $t->getMessage() );
			return;
		}

		try {
			$this->api->save_account_credentials( $account, new Gmail_Credentials( $credentials->get_project_credentials(), $access_token ) );
		} catch ( Throwable $t ) {
			WP_CLI::error( 'Refreshed the token but failed to save it: ' . $t->getMessage() );
			return;
		}

		WP_CLI::success( 'Refreshed the Gmail access token for ' . $account_email . '.' );
	}

	/**
	 * Instantiate the Gmail connection for an account.
	 *
	 * Extracted so tests can substitute a connection without performing the live OAuth refresh.
	 *
	 * @param BH_Email_Account $account The account to build the connection for.
	 */
	protected function make_connection( BH_Email_Account $account ): Gmail_Email_Connection {
		return new Gmail_Email_Connection( $account, $this->logger );
	}
}

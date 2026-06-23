<?php
/**
 * WP-CLI command to refresh a Gmail account's OAuth access token.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

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
	 * The new token is printed as JSON and the `bh_wp_mailboxes_gmail_access_token_refreshed` action is
	 * fired so it can be persisted elsewhere. The token is NOT saved by this command.
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
	 *   {
	 *       "access_token": "ya29...",
	 *       ...
	 *   }
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

		$plugin_slug = $this->settings->get_plugin_slug();

		/**
		 * Resolve the account's credentials.
		 *
		 * @see \BrianHenryIE\WP_Mailboxes\API\API::set_provider_credentials()
		 */
		$credentials = apply_filters( 'bh_wp_mailboxes_credentials', null, $plugin_slug, $account );

		if ( ! ( $credentials instanceof Google_API_Credentials_Interface ) ) {
			WP_CLI::error( 'No Gmail API credentials found for ' . $account_email . '.' );
			return;
		}

		try {
			$provider = $this->make_provider( $account );
			$provider->set_credentials( $credentials );
			$access_token = $provider->refresh_access_token();
		} catch ( Throwable $t ) {
			WP_CLI::error( $t->getMessage() );
			return;
		}

		WP_CLI::log( (string) wp_json_encode( $access_token, JSON_PRETTY_PRINT ) );

		/**
		 * Fires after a Gmail access token has been refreshed via WP-CLI.
		 *
		 * The token is not persisted by the command; hook here to save it.
		 *
		 * @param \BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Model\Access_Token $access_token  The new access token.
		 * @param string                                                           $account_email The account's email address.
		 */
		do_action( 'bh_wp_mailboxes_gmail_access_token_refreshed', $access_token, $account_email );

		WP_CLI::success( 'Refreshed the Gmail access token for ' . $account_email . '.' );
	}

	/**
	 * Instantiate the Gmail provider for an account.
	 *
	 * Extracted so tests can substitute a provider without performing the live OAuth refresh.
	 *
	 * @param BH_Email_Account $account The account to build the provider for.
	 */
	protected function make_provider( BH_Email_Account $account ): Gmail_Email_Provider {
		return new Gmail_Email_Provider( $account, $this->logger );
	}
}

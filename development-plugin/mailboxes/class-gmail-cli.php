<?php
/**
 * Dev-only WP-CLI command to connect the test Gmail account and obtain its first auth token.
 *
 * @package brianhenryie/bh-wp-mailboxes-development-plugin
 */

namespace BrianHenryIE\WP_Mailboxes_Development_Plugin\Mailboxes;

use BrianHenryIE\WP_Mailboxes\API\API;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_Email_Connection;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_CLI;

/**
 * `wp {cli_base} gmail connect`
 */
class Gmail_CLI {

	/**
	 * Constructor.
	 *
	 * @param API                                $api      The Gmail mailbox API instance.
	 * @param BH_WP_Mailboxes_Settings_Interface $settings The Gmail mailbox settings (for the CLI base).
	 * @param Gmail_API                          $gmail_api The test-credentials helper.
	 * @param LoggerInterface                    $logger   A logger.
	 */
	public function __construct(
		protected API $api,
		protected BH_WP_Mailboxes_Settings_Interface $settings,
		protected Gmail_API $gmail_api,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * Register the WP-CLI command under the mailbox's CLI base.
	 */
	public function register_commands(): void {

		$cli_base = $this->settings->get_cli_base();

		if ( is_null( $cli_base ) ) {
			return;
		}

		try {
			WP_CLI::add_command( "{$cli_base} gmail connect", array( $this, 'connect' ) );
		} catch ( Exception $e ) {
			$this->logger->error( 'Failed to register WP CLI command: ' . $e->getMessage(), array( 'exception' => $e ) );
		}
	}

	/**
	 * Connect the test Gmail account and obtain its first OAuth access token.
	 *
	 * Adds the email account (the "connection"), then — if no access token exists yet — runs the
	 * interactive authorization flow against /var/www/test-credentials/client_secret.json and saves the
	 * resulting token to /var/www/test-credentials/access_token.json.
	 *
	 * ## EXAMPLES
	 *
	 *   # Connect Gmail and authorize.
	 *   $ wp development-plugin gmail connect
	 *   Created Gmail connection for brianhenryie@gmail.com.
	 *   Open this URL in your browser and grant access:
	 *   https://accounts.google.com/o/oauth2/auth?...
	 *   Enter the verification code: 4/0A...
	 *   Success: Saved Gmail access token to /var/www/test-credentials/access_token.json.
	 *
	 * @param string[]             $_args      The unlabelled command line arguments.
	 * @param array<string,string> $_assoc_args The labelled command line arguments.
	 */
	public function connect( array $_args, array $_assoc_args ): void {

		if ( ! $this->gmail_api->is_client_secret_present() ) {
			WP_CLI::error( 'client_secret.json not found in ' . Gmail_API::CREDENTIALS_DIRECTORY . '.' );
			return;
		}

		$email = $this->gmail_api->get_account_email_address();

		// 1. Create the connection: add the email account if it does not already exist.
		try {
			$this->api->add_email_account(
				email_address: $email,
				display_name: $email,
				connection_type_class: Google_API_Credentials_Interface::class,
				from_address_regex_filter: null,
				body_identifier_regex_filter: null,
				after_download_remote_email_action: null,
				delete_local_emails_after_n_days: 1,
			);
			WP_CLI::log( "Created Gmail connection for {$email}." );
		} catch ( Exception $e ) {
			WP_CLI::log( "Gmail bh_email_account for {$email} already exists." );
		}

		// 2. Obtain the first auth token, unless one already exists.
		$credentials = $this->gmail_api->get_credentials();
		if ( ! ( $credentials instanceof Google_API_Credentials_Interface ) ) {
			WP_CLI::error( 'Gmail credentials are not the expected type.' );
			return;
		}

		if ( ! is_null( $credentials->get_access_token() ) ) {
			WP_CLI::success( "Gmail connection for {$email} is already authorized." );
			return;
		}

		$mailbox_settings = $this->gmail_api->get_mailbox_settings();
		if ( is_null( $mailbox_settings ) ) {
			WP_CLI::error( 'Could not load the Gmail mailbox settings.' );
			return;
		}

		$connection = new Gmail_Email_Connection( $mailbox_settings, $this->logger );
		$connection->set_credentials( $credentials );

		WP_CLI::log( 'Open this URL in your browser and grant access:' );
		WP_CLI::log( $connection->get_authorization_url() );

		print 'Enter the verification code (or paste the whole redirect URL): ';
		$auth_code = $this->parse_auth_code_from_url( trim( (string) fgets( STDIN ) ) );

		if ( '' === $auth_code ) {
			WP_CLI::error( 'No verification code entered.' );
			return;
		}

		try {
			$access_token = $connection->fetch_access_token_with_auth_code( $auth_code );
		} catch ( Throwable $t ) {
			WP_CLI::error( $t->getMessage() );
			return;
		}

		$token_path = Gmail_API::CREDENTIALS_DIRECTORY . '/access_token.json';
		$this->save_token( $token_path, (string) wp_json_encode( $access_token, JSON_PRETTY_PRINT ) );

		WP_CLI::success( "Saved Gmail access token to {$token_path}." );
	}

	/**
	 * Write the token JSON to disk via WP_Filesystem.
	 *
	 * @param string $token_path Absolute path to the token file.
	 * @param string $contents   JSON-encoded access token.
	 */
	protected function save_token( string $token_path, string $contents ): void {

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		/**
		 * The WordPress filesystem abstraction.
		 *
		 * @var \WP_Filesystem_Base|null $wp_filesystem
		 */
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			WP_CLI::error( 'Could not initialise WP_Filesystem to save the Gmail access token.' );
			return;
		}

		if ( ! $wp_filesystem->put_contents( $token_path, $contents, 0600 ) ) {
			WP_CLI::error( 'Failed to write the Gmail access token to ' . $token_path . '.' );
		}
	}

	/**
	 * Extract the OAuth `code` from a pasted redirect URL, or return the input unchanged.
	 *
	 * After consenting, the Desktop-app flow redirects to an unreachable loopback URL; users may paste
	 * that whole URL or just the code. E.g.
	 * `http://localhost/?iss=https://accounts.google.com&code=4/1BdkVLPxTXN…&scope=https://www.googleapis.com/auth/gmail.readonly`
	 * returns `4/1BdkVLPxTXN…`, while a bare code is returned as-is.
	 *
	 * @param string $auth_code The pasted verification code, or the full redirect URL containing it.
	 *
	 * @return string The bare authorization code.
	 */
	private function parse_auth_code_from_url( string $auth_code ): string {

		$query = wp_parse_url( $auth_code, PHP_URL_QUERY );

		if ( ! is_string( $query ) || '' === $query ) {
			return $auth_code;
		}

		parse_str( $query, $params );
		$code = $params['code'] ?? null;

		return is_string( $code ) ? $code : $auth_code;
	}
}

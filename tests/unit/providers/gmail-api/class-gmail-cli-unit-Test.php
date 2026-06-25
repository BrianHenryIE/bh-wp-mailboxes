<?php
/**
 * Unit tests for the Gmail WP-CLI command.
 *
 * `WP_CLI` is stubbed (it is only present at WP-CLI runtime) and the provider is substituted via the
 * `make_provider()` seam so the resolution logic and the fired action can be exercised without a live
 * OAuth refresh.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Model\Access_Token;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Gmail_CLI
 */
class Gmail_CLI_Unit_Test extends Unit_Testcase {

	/**
	 * Build a BH_Email_Account with the given email and provider type.
	 *
	 * @param string $email               The account email address.
	 * @param string $provider_type_class The provider type class.
	 */
	private function make_account( string $email, string $provider_type_class ): BH_Email_Account {
		return new BH_Email_Account(
			post_id: 1,
			post_type: 'bh_email_account',
			local_status: 'publish',
			provider_type_class: $provider_type_class,
			email_address: $email,
			display_name: $email,
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: null,
			last_checked_time: null,
			last_successful_login_time: null,
			last_failed_login_time: null,
		);
	}

	/**
	 * A stubbed API returning the given accounts.
	 *
	 * @param BH_Email_Account[] $accounts The accounts the API returns.
	 */
	private function make_api( array $accounts ): API_Interface {
		$api = Mockery::mock( API_Interface::class );
		$api->allows( 'get_email_accounts' )->andReturn( $accounts );
		return $api;
	}

	/**
	 * A stubbed settings object.
	 */
	private function make_settings(): BH_WP_Mailboxes_Settings_Interface {
		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );
		return $settings;
	}

	/**
	 * Build the CLI with stubbed API + settings.
	 *
	 * @param BH_Email_Account[] $accounts The accounts the API returns.
	 */
	private function make_sut( array $accounts ): Gmail_CLI {
		return new Gmail_CLI( $this->make_api( $accounts ), $this->make_settings(), $this->logger );
	}

	/**
	 * A complete access token value object.
	 */
	private function make_token(): Access_Token {
		return new Access_Token(
			access_token: 'fresh-access-token',
			expires_in: 3599,
			scope: 'https://www.googleapis.com/auth/gmail.readonly',
			token_type: 'Bearer',
			created: 1700003600,
			refresh_token: 'the-refresh-token',
		);
	}

	/**
	 * The --account option is required.
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_account_option_required(): void {
		$this->expectException( \WP_CLI\ExitException::class );
		$this->make_sut( array() )->refresh_access_token( array(), array() );
	}

	/**
	 * An unknown account is an error.
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_unknown_account_errors(): void {
		$this->expectException( \WP_CLI\ExitException::class );
		$this->make_sut( array() )->refresh_access_token( array(), array( 'account' => 'nobody@example.com' ) );
	}

	/**
	 * Non-Gmail credentials are an error.
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_missing_gmail_credentials_errors(): void {

		$account = $this->make_account( 'you@example.com', Google_API_Credentials_Interface::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )->withAnyArgs()->reply( null );

		$this->expectException( \WP_CLI\ExitException::class );
		$this->make_sut( array( $account ) )->refresh_access_token( array(), array( 'account' => 'you@example.com' ) );
	}

	/**
	 * The happy path prints the token JSON and fires the refreshed action.
	 *
	 * @covers ::refresh_access_token
	 */
	public function test_refresh_fires_action(): void {

		$account = $this->make_account( 'you@example.com', Google_API_Credentials_Interface::class );
		$token   = $this->make_token();

		$credentials = Mockery::mock( Google_API_Credentials_Interface::class );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_credentials' )->withAnyArgs()->reply( $credentials );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{"access_token":"fresh-access-token"}' );
		\WP_Mock::expectAction( 'bh_wp_mailboxes_gmail_access_token_refreshed', $token, 'you@example.com' );

		$provider = Mockery::mock( Gmail_Email_Connection::class );
		$provider->expects( 'set_credentials' )->with( $credentials )->once();
		$provider->expects( 'refresh_access_token' )->once()->andReturn( $token );

		$sut = Mockery::mock(
			Gmail_CLI::class,
			array( $this->make_api( array( $account ) ), $this->make_settings(), $this->logger )
		)
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$sut->allows( 'make_provider' )->andReturn( $provider );

		$sut->refresh_access_token( array(), array( 'account' => 'you@example.com' ) );

		$this->assertNotEmpty( \WP_CLI::$success );
	}
}

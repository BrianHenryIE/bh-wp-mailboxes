<?php
/**
 * Unit tests for the plugin's WP-CLI commands.
 *
 * `WP_CLI` and `WP_CLI\Utils\format_items()` are stubbed (they are only present at WP-CLI runtime);
 * the stub records the items passed to it so the account-listing mapping can be asserted.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\WP_Includes;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\Models\BH_Email_Account_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_API_Fixture;
use BrianHenryIE\WP_Mailboxes\Models\BH_WP_Mailboxes_Settings_Fixture;
use BrianHenryIE\WP_Mailboxes\Connections\Gmail_API\Google_API_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use DateTimeImmutable;
use DateTimeInterface;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\WP_Includes\CLI
 */
class CLI_Unit_Test extends Unit_Testcase {

	/**
	 * Build a BH_Email_Account.
	 *
	 * @param string             $email             The account email address.
	 * @param string             $display_name      The account display name.
	 * @param bool               $active            Whether the account is active.
	 * @param ?DateTimeInterface $last_checked_time The last-checked time.
	 */
	private function make_account( string $email, string $display_name, bool $active, ?DateTimeInterface $last_checked_time ): BH_Email_Account {
		return new BH_Email_Account(
			post_id: 12,
			post_type: 'bh_email_account',
			local_status: $active ? 'bh_email_ac_active' : 'bh_email_ac_inactive',
			provider_type_class: Google_API_Credentials_Interface::class,
			email_address: $email,
			display_name: $display_name,
			from_address_regex_filter: null,
			body_identifier_regex_filter: null,
			after_download_remote_email_action: null,
			delete_local_emails_after_n_days: null,
			last_checked_time: $last_checked_time,
			last_successful_login_time: null,
			last_failed_login_time: null,
		);
	}

	/**
	 * Build the CLI with a stubbed API returning the given accounts.
	 *
	 * @param BH_Email_Account[] $accounts The accounts the API returns.
	 */
	private function make_sut( array $accounts ): CLI {

		$api = Mockery::mock( API_Interface::class );
		$api->allows( 'get_email_accounts' )->andReturn( $accounts );

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( 'test-plugin' );

		return new CLI( $api, $settings, $this->logger );
	}

	/**
	 * The configured accounts are mapped to display rows and handed to format_items().
	 *
	 * @covers ::list_accounts
	 * @covers ::short_provider_name
	 */
	public function test_list_accounts_outputs_rows(): void {

		$account = $this->make_account(
			'you@example.com',
			'You',
			true,
			new DateTimeImmutable( '2026-06-21T10:00:00+00:00' )
		);

		\WP_CLI\Utils::$format_items = array();

		$this->make_sut( array( $account ) )->list_accounts( array(), array() );

		$captured = \WP_CLI\Utils::$format_items;

		$this->assertCount( 1, $captured );
		$call = $captured[0];

		$this->assertSame( 'table', $call['format'] );
		$this->assertSame( array( 'id', 'email', 'name', 'provider', 'active', 'last_checked' ), $call['fields'] );

		$row = $call['items'][0];
		$this->assertSame( 12, $row['id'] );
		$this->assertSame( 'you@example.com', $row['email'] );
		$this->assertSame( 'You', $row['name'] );
		$this->assertSame( 'Google_API_Credentials_Interface', $row['provider'] );
		$this->assertSame( 'yes', $row['active'] );
		$this->assertSame( '2026-06-21T10:00:00+00:00', $row['last_checked'] );
	}

	/**
	 * The --format option is passed through to format_items().
	 *
	 * @covers ::list_accounts
	 */
	public function test_list_accounts_passes_format(): void {

		\WP_CLI\Utils::$format_items = array();

		$this->make_sut( array() )->list_accounts( array(), array( 'format' => 'json' ) );

		$this->assertSame( 'json', \WP_CLI\Utils::$format_items[0]['format'] );
		$this->assertSame( array(), \WP_CLI\Utils::$format_items[0]['items'] );
	}

	/**
	 * Each registered mailbox becomes a row, including its account count.
	 *
	 * @covers ::list_mailboxes
	 */
	public function test_list_mailboxes_outputs_rows(): void {

		$imap_settings = BH_WP_Mailboxes_Settings_Fixture::make( 'development-plugin', 'imap_email_env', 'imap_accounts_env', 'IMAP Email ENV' );
		$imap_accounts = array( BH_Email_Account_Fixture::make() );
		$imap_mailbox  = BH_WP_Mailboxes_API_Fixture::make( $imap_settings, $imap_accounts, );

		$fixtures_settings = BH_WP_Mailboxes_Settings_Fixture::make( 'development-plugin', 'fixtures_email', 'fixtures_accounts', 'Fixtures Email' );
		$fixtures_accounts = array( BH_Email_Account_Fixture::make(), BH_Email_Account_Fixture::make() );

		$mailboxes = array(
			$imap_mailbox,
			BH_WP_Mailboxes_API_Fixture::make( $fixtures_settings, $fixtures_accounts, ),
		);

		$plugin_slug = 'test-plugin';

		\WP_Mock::userFunction( 'plugin_basename' )->andReturn( "$plugin_slug/includes/subdir/" );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_registered_mailboxes' )->with( array(), $plugin_slug )->reply( $mailboxes );

		\WP_CLI\Utils::$format_items = array();

		$sut = new CLI( $mailboxes[0], $imap_settings, $this->logger );
		$sut->list_mailboxes( array(), array() );

		$call = \WP_CLI\Utils::$format_items[0];

		$this->assertSame( 'table', $call['format'] );
		$this->assertSame( array( 'emails', 'emails_cpt', 'accounts', 'accounts_cpt', 'accounts_count' ), $call['fields'] );
		$this->assertCount( 2, $call['items'] );

		$this->assertSame( 'imap_email_env', $call['items'][0]['emails_cpt'] );
		$this->assertSame( 1, $call['items'][0]['accounts_count'] );
		$this->assertSame( 'Fixtures Email', $call['items'][1]['emails'] );
		$this->assertSame( 2, $call['items'][1]['accounts_count'] );
	}

	/**
	 * Malformed registry entries are skipped rather than fatal.
	 *
	 * @covers ::list_mailboxes
	 */
	public function test_list_mailboxes_skips_invalid_entries(): void {

		$imap_settings = BH_WP_Mailboxes_Settings_Fixture::make( 'test-plugin', 'imap_email_env', 'imap_accounts_env', 'IMAP Email ENV' );
		$imap_accounts = array( BH_Email_Account_Fixture::make() );

		$mailboxes = array(
			BH_WP_Mailboxes_API_Fixture::make( $imap_settings, $imap_accounts, ),
		);

		$plugin_slug = 'test-plugin';

		\WP_Mock::userFunction( 'plugin_basename' )->andReturn( "$plugin_slug/includes/subdir/" );

		\WP_Mock::onFilter( 'bh_wp_mailboxes_registered_mailboxes' )->with( array(), $plugin_slug )->reply( $mailboxes );

		\WP_CLI\Utils::$format_items = array();

		$this->make_sut( array() )->list_mailboxes( array(), array( 'format' => 'json' ) );

		$call = \WP_CLI\Utils::$format_items[0];
		$this->assertSame( 'json', $call['format'] );
		$this->assertCount( 1, $call['items'] );
	}
}

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
use BrianHenryIE\WP_Mailboxes\Providers\Gmail_API\Google_API_Credentials_Interface;
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
	 * A registered mailbox descriptor for the filter to return.
	 *
	 * @param string $slug          The plugin slug.
	 * @param string $emails_cpt    The emails CPT name.
	 * @param string $accounts_cpt  The accounts CPT name.
	 * @param string $friendly_name The emails CPT friendly name.
	 * @param int    $account_count How many accounts the mailbox's API returns.
	 *
	 * @return array{api:API_Interface,settings:BH_WP_Mailboxes_Settings_Interface}
	 */
	private function make_mailbox( string $slug, string $emails_cpt, string $accounts_cpt, string $friendly_name, int $account_count ): array {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( $slug );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $emails_cpt );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $accounts_cpt );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( $friendly_name );

		$api = Mockery::mock( API_Interface::class );
		$api->allows( 'get_email_accounts' )->andReturn( array_fill( 0, $account_count, 'account' ) );

		return array(
			'api'      => $api,
			'settings' => $settings,
		);
	}

	/**
	 * Each registered mailbox becomes a row, including its account count.
	 *
	 * @covers ::list_mailboxes
	 */
	public function test_list_mailboxes_outputs_rows(): void {

		$mailboxes = array(
			$this->make_mailbox( 'development-plugin', 'imap_email_env', 'imap_accounts_env', 'IMAP Email ENV', 1 ),
			$this->make_mailbox( 'development-plugin', 'fixtures_email', 'fixtures_accounts', 'Fixtures Email', 2 ),
		);

		\WP_Mock::onFilter( 'bh_wp_mailboxes_registered_mailboxes' )->with( array() )->reply( $mailboxes );

		\WP_CLI\Utils::$format_items = array();

		$this->make_sut( array() )->list_mailboxes( array(), array() );

		$call = \WP_CLI\Utils::$format_items[0];

		$this->assertSame( 'table', $call['format'] );
		$this->assertSame( array( 'slug', 'emails_cpt', 'accounts_cpt', 'name', 'accounts' ), $call['fields'] );
		$this->assertCount( 2, $call['items'] );

		$this->assertSame( 'imap_email_env', $call['items'][0]['emails_cpt'] );
		$this->assertSame( 1, $call['items'][0]['accounts'] );
		$this->assertSame( 'Fixtures Email', $call['items'][1]['name'] );
		$this->assertSame( 2, $call['items'][1]['accounts'] );
	}

	/**
	 * Malformed registry entries are skipped rather than fatal.
	 *
	 * @covers ::list_mailboxes
	 */
	public function test_list_mailboxes_skips_invalid_entries(): void {

		$mailboxes = array(
			'not-an-array',
			array( 'settings' => 'wrong-type' ),
			$this->make_mailbox( 'development-plugin', 'imap_email_env', 'imap_accounts_env', 'IMAP Email ENV', 0 ),
		);

		\WP_Mock::onFilter( 'bh_wp_mailboxes_registered_mailboxes' )->with( array() )->reply( $mailboxes );

		\WP_CLI\Utils::$format_items = array();

		$this->make_sut( array() )->list_mailboxes( array(), array( 'format' => 'json' ) );

		$call = \WP_CLI\Utils::$format_items[0];
		$this->assertSame( 'json', $call['format'] );
		$this->assertCount( 1, $call['items'] );
	}
}

<?php
/**
 * Tests the default method implementations in BH_WP_Mailboxes_Settings_Defaults_Trait.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes;

/**
 * @coversDefaultClass \BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait
 */
class BH_WP_Mailboxes_Settings_Defaults_Trait_WPUnit_Test extends WPUnit_Testcase {

	/**
	 * Build a settings object using the defaults trait, with configurable friendly names + slug.
	 *
	 * @param string $plugin_slug       The plugin slug.
	 * @param string $emails_friendly   The emails CPT friendly name.
	 * @param string $accounts_friendly The email-accounts CPT friendly name.
	 */
	private function make_settings(
		string $plugin_slug = 'test-plugin',
		string $emails_friendly = 'Test Emails CPT',
		string $accounts_friendly = 'Test Accounts CPT'
	): BH_WP_Mailboxes_Settings_Interface {
		return new class( $plugin_slug, $emails_friendly, $accounts_friendly ) implements BH_WP_Mailboxes_Settings_Interface {
			use BH_WP_Mailboxes_Settings_Defaults_Trait;

			/**
			 * @param string $plugin_slug       The plugin slug.
			 * @param string $emails_friendly   The emails CPT friendly name.
			 * @param string $accounts_friendly The email-accounts CPT friendly name.
			 */
			public function __construct(
				private string $plugin_slug,
				private string $emails_friendly,
				private string $accounts_friendly,
			) {}

			public function get_plugin_slug(): string {
				return $this->plugin_slug;
			}

			public function get_emails_cpt_friendly_name(): string {
				return $this->emails_friendly;
			}

			public function get_email_accounts_cpt_friendly_name(): string {
				return $this->accounts_friendly;
			}
		};
	}

	/**
	 * The emails CPT dashed name is the friendly name run through sanitize_title().
	 *
	 * @covers ::get_emails_cpt_dashed
	 */
	public function test_get_emails_cpt_dashed(): void {
		$this->assertSame( 'test-emails-cpt', $this->make_settings()->get_emails_cpt_dashed() );
	}

	/**
	 * The accounts CPT dashed name derives from the *accounts* friendly name (not the emails one).
	 *
	 * @covers ::get_email_accounts_cpt_dashed
	 */
	public function test_get_email_accounts_cpt_dashed(): void {
		$this->assertSame( 'test-accounts-cpt', $this->make_settings()->get_email_accounts_cpt_dashed() );
	}

	/**
	 * The emails CPT key replaces dashes with underscores.
	 *
	 * @covers ::get_emails_cpt_underscored_20
	 */
	public function test_get_emails_cpt_underscored_20(): void {
		$this->assertSame( 'test_emails_cpt', $this->make_settings()->get_emails_cpt_underscored_20() );
	}

	/**
	 * The accounts CPT key derives from the accounts friendly name, underscored.
	 *
	 * @covers ::get_email_accounts_cpt_underscored_20
	 */
	public function test_get_email_accounts_cpt_underscored_20(): void {
		$this->assertSame( 'test_accounts_cpt', $this->make_settings()->get_email_accounts_cpt_underscored_20() );
	}

	/**
	 * The CPT key is truncated to 20 characters (register_post_type's limit).
	 *
	 * @covers ::get_emails_cpt_underscored_20
	 */
	public function test_emails_cpt_underscored_is_truncated_to_20_chars(): void {
		$settings = $this->make_settings( emails_friendly: 'Super Long Mailboxes Emails Name' );
		$key      = $settings->get_emails_cpt_underscored_20();

		$this->assertSame( 'super_long_mailboxes', $key );
		$this->assertSame( 20, strlen( $key ) );
	}

	/**
	 * The emails and accounts CPT keys must differ — register_post_type and validate_settings()
	 * both reject a collision.
	 *
	 * @covers ::get_emails_cpt_underscored_20
	 * @covers ::get_email_accounts_cpt_underscored_20
	 */
	public function test_emails_and_accounts_cpt_keys_are_distinct(): void {
		$settings = $this->make_settings();

		$this->assertNotSame(
			$settings->get_emails_cpt_underscored_20(),
			$settings->get_email_accounts_cpt_underscored_20(),
		);
	}

	/**
	 * The private uploads directory name combines the plugin slug with an attachments suffix when
	 * the bh-wp-private-uploads library is available (it is, as a dependency).
	 *
	 * @covers ::get_private_uploads_directory_name
	 */
	public function test_get_private_uploads_directory_name(): void {
		$settings = $this->make_settings( plugin_slug: 'my-mailboxes' );

		$this->assertSame( 'my-mailboxes-email-attachments', $settings->get_private_uploads_directory_name() );
	}

	/**
	 * The default cron schedules: hourly fetch, daily local delete.
	 *
	 * @covers ::get_cron_schedules
	 */
	public function test_get_cron_schedules(): void {
		$this->assertSame(
			array(
				'fetch_emails'        => 'hourly',
				'delete_local_emails' => 'daily',
			),
			$this->make_settings()->get_cron_schedules(),
		);
	}

	/**
	 * The CLI base defaults to the plugin slug.
	 *
	 * @covers ::get_cli_base
	 */
	public function test_get_cli_base_defaults_to_plugin_slug(): void {
		$this->assertSame( 'my-mailboxes', $this->make_settings( plugin_slug: 'my-mailboxes' )->get_cli_base() );
	}
}

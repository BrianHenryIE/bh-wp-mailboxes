<?php

namespace BrianHenryIE\WP_Mailboxes\API\Gmail_API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

class Gmail_Email_Fetcher_WPUnit_Test extends \Codeception\TestCase\WPTestCase {

	protected Mailbox_Settings_Interface $mailbox_settings;

	protected BH_WP_Mailboxes_Settings_Interface $settings;

	public function setUp(): void {

		$logger = new ColorLogger();

		$this->mailbox_settings = $this->makeEmpty(
			Mailbox_Settings_Interface::class,
			array( 'get_account_friendly_name' => 'brianhenryie@gmail.com' )
		);
		$this->settings         = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => 'test-plugin',
				'get_plugin_slug'        => 'test-plugin',
				'get_mailboxes'          => array( $this->mailbox_settings ),
			)
		);

		$cpt = new BH_Email_CPT( $this->settings, $logger );

		$account_category_slug = sanitize_title( 'brianhenryie@gmail.com' );

		// false when it does not exist.
		$mailbox_category = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		assert( false === $mailbox_category );

		$cpt->register_cpt();
		$cpt->register_mailboxes_taxonomy();
		$cpt->register_mailbox();
	}

	public function test_one(): void {

		$logger      = new ColorLogger();
		$credentials = new class() implements Google_API_Credentials_Interface {

			public function get_project_credentials(): array {
				return json_decode( file_get_contents( __DIR__ . '/credentials.json' ), true );
			}

			public function get_access_token(): ?array {
				return json_decode( file_get_contents( __DIR__ . '/token.json' ), true );
			}
		};

		$mailbox_settings = $this->mailbox_settings;

		$mailbox_settings->method( 'get_credentials' )->willReturn( $credentials );

		$cpt = $this->settings->get_cpt_underscored_20();

		$sut = new Gmail_Email_Fetcher( $cpt, $mailbox_settings, $logger );

		$since_datetime = \DateTime::createFromFormat( 'Y-m-d', '1970-1-1' );

		$result = $sut->retrieve_emails( $since_datetime );

	}

}

<?php

namespace BrianHenryIE\WP_Mailboxes\API\Gmail_API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Mailbox_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

class Gmail_Email_Fetcher_WPUnit_Test extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {

		$logger = new ColorLogger();

		$mailbox  = $this->makeEmpty(
			Mailbox_Settings_Interface::class,
			array( 'get_account_friendly_name' => 'brianhenryie@gmail.com' )
		);
		$settings = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_mailboxes' => array( $mailbox ),
			)
		);

		$sut = new BH_Email_CPT( $settings, $logger );

		$account_category_slug = sanitize_title( 'brianhenryie@gmail.com' );

		// false when it does not exist.
		$mailbox_category = get_term_by( 'slug', $account_category_slug, 'bh-wp-mailbox-account' );

		assert( false === $mailbox_category );

		$sut->register_mailboxes_taxonomy();
		$sut->register_mailbox();
	}

	public function test_one(): void {

		$logger   = new ColorLogger();
		$settings = new class() implements Mailbox_Settings_Interface {
			use Mailbox_Settings_Defaults_Trait;

			public function get_account_unique_friendly_name(): string {
				return 'brianhenryie@gmail.com';
			}

			public function get_credentials(): Account_Credentials_Interface {
				return new class() implements Google_API_Credentials_Interface {

					public function get_project_credentials(): array {
						return json_decode( file_get_contents( __DIR__ . '/credentials.json' ), true );
					}

					public function get_access_token(): ?array {
						return json_decode( file_get_contents( __DIR__ . '/token.json' ), true );
					}
				};
			}
		};

		$sut = new Gmail_Email_Fetcher( $settings, $logger );

		$since_datetime = \DateTime::createFromFormat( 'Y-m-d', '1970-1-1' );

		$result = $sut->retrieve_emails( $since_datetime );

	}

}

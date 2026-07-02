<?php

namespace BrianHenryIE\WP_Mailboxes\Connections\Gmail_API;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\WP_Mailboxes\Account_Credentials_Interface;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account_CPT;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Unit_Testcase;
use BrianHenryIE\WP_Mailboxes\WP_Includes\BH_Email_CPT;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

class Gmail_Email_Fetcher_Contract_Test extends Unit_Testcase {

	/** @var BH_WP_Mailboxes_Settings_Interface Mock settings for the library. */
	protected BH_WP_Mailboxes_Settings_Interface $settings;

	/** @var Email_Account_Settings_Interface A mock email account. */
	protected Email_Account_Settings_Interface $mailbox_settings;

	public function setUp(): void {

		$logger = new ColorLogger();

		$this->settings         = $this->makeEmpty(
			BH_WP_Mailboxes_Settings_Interface::class,
			array(
				'get_cpt_underscored_20' => 'test-plugin',
				'get_plugin_slug'        => 'test-plugin',
				'get_mailboxes'          => array( $this->mailbox_settings ),
			)
		);
		$this->mailbox_settings = $this->makeEmpty(
			Email_Account_Settings_Interface::class,
			array( 'get_account_friendly_name' => 'brianhenryie@gmail.com' )
		);

		$email_cpt = new BH_Email_CPT( $this->settings, $logger );
		$email_cpt->register_cpt();
		$email_account_cpt = new BH_Email_Account_CPT( $this->settings, $logger );
		$email_account_cpt->register_cpt();
	}

	public function test_one(): void {

		$logger      = new ColorLogger();
		$credentials = new Google_API_Credentials(
			codecept_root_dir( '/test-credentials/' ),
		);

		$mailbox_settings = $this->mailbox_settings;

		$mailbox_settings->method( 'get_credentials' )->willReturn( $credentials );

		$cpt = $this->settings->get_emails_cpt_underscored_20();

		$sut = new Gmail_Email_Connection( $cpt, $mailbox_settings, $logger );

		$since_datetime = \DateTime::createFromFormat( 'Y-m-d', '1970-1-1' );

		$result = $sut->retrieve_emails( $since_datetime );
	}
}

<?php

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Mockery;

class BH_WP_Mailboxes_Settings_Fixture  {
	public static function make(
		string $plugin_slug = 'test-plugin',
		string $email_cpt = 'test_emails',
		string $accounts_cpt = 'test_email_accounts',
		string $email_cpt_display = 'Test Emails',
		string $email_accounts_cpt_display = 'Test Email Accounts',
	): BH_WP_Mailboxes_Settings_Interface {

		$settings = Mockery::mock( BH_WP_Mailboxes_Settings_Interface::class );
		$settings->allows( 'get_plugin_slug' )->andReturn( $plugin_slug );
		$settings->allows( 'get_emails_cpt_underscored_20' )->andReturn( $email_cpt );
		$settings->allows( 'get_email_accounts_cpt_underscored_20' )->andReturn( $accounts_cpt );
		$settings->allows( 'get_emails_cpt_friendly_name' )->andReturn( $email_cpt_display );
		$settings->allows( 'get_email_accounts_cpt_friendly_name' )->andReturn( $email_accounts_cpt_display );

		return $settings;
	}
}
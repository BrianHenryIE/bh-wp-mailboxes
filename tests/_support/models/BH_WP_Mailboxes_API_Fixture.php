<?php

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Mockery;

class BH_WP_Mailboxes_API_Fixture  {
	public static function make(
		?BH_WP_Mailboxes_Settings_Interface $settings = null,
		?array $email_accounts = null,
	): API_Interface {

		$mailbox = Mockery::mock( API_Interface::class );

		$mailbox->expects('get_settings')->andReturn($settings);
		$mailbox->expects('get_email_accounts')->andReturn($email_accounts);
		return $mailbox;

	}
}
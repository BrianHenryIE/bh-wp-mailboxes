<?php

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Defaults_Trait;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;

class BH_WP_Mailboxes_Settings  {
	public static function make(): BH_WP_Mailboxes_Settings_Interface {
		return new class() implements BH_WP_Mailboxes_Settings_Interface {
			use BH_WP_Mailboxes_Settings_Defaults_Trait;

			public function get_plugin_slug(): string {
				return 'test-plugin';
			}

			public function get_emails_cpt_friendly_name(): string {
				return 'Test Emails';
			}

			public function get_email_accounts_cpt_friendly_name(): string {
				return 'Test Email Accounts';
			}
		};
	}
}
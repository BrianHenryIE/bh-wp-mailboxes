<?php

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\Providers\Imap\ImapEngine_Imap_Email_Fetcher;

class BH_Email_Account_Fixture {
	public static function make(
		?int $post_id = 2,
		?string $post_type = 'test-email-account',
		?string $status = 'bh_email_ac_active',
		?string $provider_type_class = ImapEngine_Imap_Email_Fetcher::class,
		?string $email_address = 'test@example.org',
		?string $display_name = 'Brian Henry',
		?string $from_address_regex_filter = null,
		?string $body_identifier_regex_filter = null,
		?string $after_download_email_action = null,
		?int $delete_local_emails_after_n_days = 7,
		?\DateTimeInterface $last_successful_login_time = null,
		?\DateTimeInterface $last_failed_login_time = null,
	): BH_Email_Account {
		return new BH_Email_Account(
			post_id: $post_id,
			post_type: $post_type,
			local_status: $status,
			provider_type_class: $provider_type_class,
			email_address: $email_address,
			display_name: $display_name,
			from_address_regex_filter: $from_address_regex_filter,
			body_identifier_regex_filter: $body_identifier_regex_filter,
			after_download_email_action: $after_download_email_action,
			delete_local_emails_after_n_days: $delete_local_emails_after_n_days,
			last_successful_login_time: $last_successful_login_time,
			last_failed_login_time: $last_failed_login_time,
		);
	}
}
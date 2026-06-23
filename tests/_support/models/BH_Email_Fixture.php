<?php

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use Psr\Log\NullLogger;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

class BH_Email_Fixture {

	public static function make_from_file(
		?string $file_path = null,
		?BH_WP_Mailboxes_Settings_Interface $mailbox_settings = null,
		?BH_Email_Account $email_account = null,
		?Email_WP_Post_Repository $repo = null
	): BH_Email {
		$file_path     ??= codecept_root_dir( 'tests/_data/wpunit/html-and-plaintext.eml' );
		$email_contents = (string) file_get_contents( $file_path );

		$mailbox_settings ??= BH_WP_Mailboxes_Settings_Fixture::make();

		$repo ??= new Email_WP_Post_Repository(
			$mailbox_settings->get_emails_cpt_underscored_20(),
			new BH_Email_Factory( new NullLogger() ),
			new NullLogger()
		);

		$parser = new MailMimeParser();
		/** @var IMessage $email */
		$email = $parser->parse( $email_contents, true );

		$email_account ??= BH_Email_Account_Fixture::make(
			post_id: 321,
			post_type: $mailbox_settings->get_email_accounts_cpt_underscored_20(),
			provider_type_class: 'SomeProvider',
			email_address: 'contact@bhwp.ie',
			display_name: 'Test Account',
		);

		$fetched_email = new Fetched_Email(
			$email,
			new Remote_Email_Coordinates( message_id: $email->getMessageId() ?? '' ),
			false,
		);

		$bh_email = $repo->save_new( $fetched_email, $mailbox_settings, $email_account );

		return $bh_email;
	}
}
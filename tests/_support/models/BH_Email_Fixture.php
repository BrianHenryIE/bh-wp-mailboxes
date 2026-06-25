<?php

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use DateTimeInterface;
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
			provider_type_class: 'SomeConnection',
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

	public static function clone_and_change(
		BH_Email $bh_email,
		?int $post_id = null,
		?string $post_type = null,
		?int $email_account_local_id = null,
		?IMessage $imessage = null,
		?string $message_id = null,
		?string $subject = null,
		?string $from_email = null,
		?string $from_name = null,
		?string $original_mime_message = null,
		?string $body_plain_text = null,
		?string $body_html = null,
		?array $attachment_ids = null,
		?DateTimeInterface $sent_at = null,
		?DateTimeInterface $downloaded_at = null,
		?DateTimeInterface $last_updated = null,
		?string $local_status = null,
		?bool $is_remote_read = null,
		?bool $is_remote_deleted = null,
		?Remote_Email_Coordinates $remote_coordinates = null,
	): BH_Email {
		return new BH_Email(
			post_id: $post_id ?? $bh_email->post_id,
			post_type: $post_type ?? $bh_email->post_type,
			email_account_local_id: $email_account_local_id ?? $bh_email->email_account_local_id,
			imessage: $imessage ?? $bh_email->imessage,
			message_id: $message_id ?? $bh_email->message_id,
			subject: $subject ?? $bh_email->subject,
			from_email: $from_email ?? $bh_email->from_email,
			from_name: $from_name ?? $bh_email->from_name,
			original_mime_message: $original_mime_message ?? $bh_email->original_mime_message,
			body_plain_text: $body_plain_text ?? $bh_email->body_plain_text,
			body_html: $body_html ?? $bh_email->body_html,
			attachment_ids: $attachment_ids ?? $bh_email->attachment_ids,
			sent_at: $sent_at ?? $bh_email->sent_at,
			downloaded_at: $downloaded_at ?? $bh_email->downloaded_at,
			last_updated: $last_updated ?? $bh_email->last_updated,
			local_status: $local_status ?? $bh_email->local_status,
			is_remote_read: $is_remote_read ?? $bh_email->is_remote_read,
			is_remote_deleted: $is_remote_deleted ?? $bh_email->is_remote_deleted,
			remote_coordinates: $remote_coordinates ?? $bh_email->remote_coordinates,
		);
	}
}
<?php
/**
 * NB: requires `$wpdb`.
 */

namespace BrianHenryIE\WP_Mailboxes\Models;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Fetched_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use BrianHenryIE\WP_Mailboxes\API\Repositories\Email_WP_Post_Repository;
use BrianHenryIE\WP_Mailboxes\API\Factories\BH_Email_Factory;
use BrianHenryIE\WP_Mailboxes\BH_Email_Account;
use BrianHenryIE\WP_Mailboxes\BH_WP_Mailboxes_Settings_Interface;
use BrianHenryIE\WP_Private_Uploads\API_Interface as Private_Uploads_API_Interface;
use DateTimeImmutable;
use DateTimeInterface;
use Mockery;
use Psr\Log\NullLogger;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

class BH_Email_Fixture {

	/**
	 * Return an unsaved BH_Email.
	 */
	public static function create_from_file(
		?string $file_path = null,
		?BH_WP_Mailboxes_Settings_Interface $mailbox_settings = null,
		?BH_Email_Account $email_account = null,
	): BH_Email {
		$repo = Mockery::mock(Email_WP_Post_Repository::class);
		$repo->expects('save_new')->andReturnUsing(
			function(
				Fetched_Email $fetched_email,
				BH_WP_Mailboxes_Settings_Interface $mailbox_settings,
				BH_Email_Account $email_account,
				?Private_Uploads_API_Interface $private_uploads = null // TODO: Is the strict typing allowed when the library is optional?
			):BH_Email {

				$email       = $fetched_email->message;
				$coordinates = $fetched_email->coordinates;

				$attachment_parts                     = $email->getAllAttachmentParts();
				$all_parts                            = $email->getAllParts();
				$non_attachment_parts                 = array_filter(
					$all_parts,
					fn( $part ) => ! in_array( $part, $attachment_parts, true )
				);
				$original_email_no_attachments_string = implode( ' ', $non_attachment_parts );

				$from_header = $email->getHeader( 'From' );
				$sender_email      = $from_header instanceof AddressHeader ? $from_header->getEmail() ?? '' : '';
				$sender_name      = $from_header instanceof AddressHeader ? $from_header->getName() ?? '' : '';

				// "Date: Wed, 30 Jul 2025 03:38:07 +0000";
				$date_header = str_replace( 'Date: ', '', (string) $email->getHeader( 'Date' ) );
				// 29 May 2026 06:36:13 -0700
				$sent_at_result = DateTimeImmutable::createFromFormat( DateTimeInterface::RFC2822, $date_header );
				$sent_at        = ( false !== $sent_at_result ) ? $sent_at_result : null;

				return self::new(
					post_id: 123,
					post_type: $mailbox_settings->get_emails_cpt_underscored_20(),
					email_account_local_id: $email_account->get_post_id(),
					imessage: $fetched_email->message,
					message_id: $coordinates->message_id,
					subject: $fetched_email->message->getSubject(),
					from_email: $sender_email,
					from_name: $sender_name,
					original_mime_message: $original_email_no_attachments_string,
					body_plain_text: $fetched_email->message->getTextContent(),
					body_html: $fetched_email->message->getHtmlContent(),
					attachment_ids: [], // TODO.
					sent_at: $sent_at,
					downloaded_at: new DateTimeImmutable(),
					last_updated: new DateTimeImmutable(),
					local_status: 'bh_email_new',
					is_remote_read: $fetched_email->is_remote_read,
					is_remote_deleted: false,
					remote_coordinates: $coordinates,
				);
			}
		);
		return self::make_from_file(
			$file_path,
			$mailbox_settings,
			$email_account,
			$repo,
		);
	}

	/**
	 * Return a saved BH_Email. Saves in wpdb when a mock repository is not provided.
	 */
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
			connection_type_class: 'SomeConnection',
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

	public static function make_from_string(
		?string $email_contents = null,
		?BH_WP_Mailboxes_Settings_Interface $mailbox_settings = null,
		?BH_Email_Account $email_account = null,
		?Email_WP_Post_Repository $repo = null
	): BH_Email {
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
			connection_type_class: 'SomeConnection',
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

	public static function create(
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
		?BH_Email $from_bh_email = null, // Use for default values if not provided.
	): BH_Email {
		$from_bh_email ??= self::create_from_file();
		return self::make(
			post_id: $post_id ?? $from_bh_email?->post_id,
			post_type: $post_type ?? $from_bh_email?->post_type,
			email_account_local_id: $email_account_local_id ?? $from_bh_email?->email_account_local_id,
			imessage: $imessage ?? $from_bh_email?->imessage,
			message_id: $message_id ?? $from_bh_email?->message_id,
			subject: $subject ?? $from_bh_email?->subject,
			from_email: $from_email ?? $from_bh_email?->from_email,
			from_name: $from_name ?? $from_bh_email?->from_name,
			original_mime_message: $original_mime_message ?? $from_bh_email?->original_mime_message,
			body_plain_text: $body_plain_text ?? $from_bh_email?->body_plain_text,
			body_html: $body_html ?? $from_bh_email?->body_html,
			attachment_ids: $attachment_ids ?? $from_bh_email?->attachment_ids,
			sent_at: $sent_at ?? $from_bh_email?->sent_at,
			downloaded_at: $downloaded_at ?? $from_bh_email?->downloaded_at,
			last_updated: $last_updated ?? $from_bh_email?->last_updated,
			local_status: $local_status ?? $from_bh_email?->local_status,
			is_remote_read: $is_remote_read ?? $from_bh_email?->is_remote_read,
			is_remote_deleted: $is_remote_deleted ?? $from_bh_email?->is_remote_deleted,
			remote_coordinates: $remote_coordinates ?? $from_bh_email?->remote_coordinates,
			from_bh_email: $from_bh_email,
		);
	}

	public static function make(
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
		?BH_Email $from_bh_email = null, // Use for default values if not provided.
	): BH_Email {
		$from_bh_email ??= self::make_from_file();
		return new BH_Email(
			post_id: $post_id ?? $from_bh_email?->post_id,
			post_type: $post_type ?? $from_bh_email?->post_type,
			email_account_local_id: $email_account_local_id ?? $from_bh_email?->email_account_local_id,
			imessage: $imessage ?? $from_bh_email?->imessage,
			message_id: $message_id ?? $from_bh_email?->message_id,
			subject: $subject ?? $from_bh_email?->subject,
			from_email: $from_email ?? $from_bh_email?->from_email,
			from_name: $from_name ?? $from_bh_email?->from_name,
			original_mime_message: $original_mime_message ?? $from_bh_email?->original_mime_message,
			body_plain_text: $body_plain_text ?? $from_bh_email?->body_plain_text,
			body_html: $body_html ?? $from_bh_email?->body_html,
			attachment_ids: $attachment_ids ?? $from_bh_email?->attachment_ids,
			sent_at: $sent_at ?? $from_bh_email?->sent_at,
			downloaded_at: $downloaded_at ?? $from_bh_email?->downloaded_at,
			last_updated: $last_updated ?? $from_bh_email?->last_updated,
			local_status: $local_status ?? $from_bh_email?->local_status,
			is_remote_read: $is_remote_read ?? $from_bh_email?->is_remote_read,
			is_remote_deleted: $is_remote_deleted ?? $from_bh_email?->is_remote_deleted,
			remote_coordinates: $remote_coordinates ?? $from_bh_email?->remote_coordinates,
		);
	}

	/**
	 * Really just the BH_Email constructor.
	 */
	public static function new(
		int $post_id,
		string $post_type,
		int $email_account_local_id,
		IMessage $imessage,
		string $message_id,
		string $subject,
		string $from_email,
		?string $from_name = null,
		string $original_mime_message = '',
		?string $body_plain_text = '',
		?string $body_html = '',
		?array $attachment_ids = null, // `null` when configured not to save attachments.
		?DateTimeInterface $sent_at = null, // `null` implies an issue parsing the date.
		?DateTimeInterface $downloaded_at = null,
		?DateTimeInterface $last_updated = null, // I'm not sure this can be null.
		string $local_status = 'bh_email_new',
		?bool $is_remote_read = null,
		?bool $is_remote_deleted = null,
		?Remote_Email_Coordinates $remote_coordinates = null,
	): BH_Email {
		return new BH_Email(
			post_id: $post_id,
			post_type: $post_type,
			email_account_local_id: $email_account_local_id,
			imessage: $imessage,
			message_id: $message_id,
			subject: $subject,
			from_email: $from_email,
			from_name: $from_name,
			original_mime_message: $original_mime_message,
			body_plain_text: $body_plain_text,
			body_html: $body_html,
			attachment_ids: $attachment_ids,
			sent_at: $sent_at,
			downloaded_at: $downloaded_at,
			last_updated: $last_updated,
			local_status: $local_status,
			is_remote_read: $is_remote_read,
			is_remote_deleted: $is_remote_deleted,
			remote_coordinates: $remote_coordinates,
		);
	}
}
<?php

namespace BrianHenryIE\WP_Mailboxes\API\Repositories\Factories;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use DateTime;
use DateTimeZone;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Post;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

class BH_Email_Factory {
	use LoggerAwareTrait;

	public function __construct(
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	public function from_wp_post( WP_Post $post ): BH_Email {

		$parser = new MailMimeParser();

		$message = $parser->parse( $post->post_content, true );

		$from_header = $message->getHeader( 'From' );
		$from_email  = '';
		$from_name   = null;
		if ( $from_header instanceof AddressHeader ) {
			$from_email = $from_header->getEmail() ?? '';
			$person     = $from_header->getPersonName();
			$from_name  = ( '' !== (string) $person ) ? $person : null;
		}

		$post_id = $post->ID;

		$is_read_raw = get_post_meta( $post_id, 'is_read_remote', true );
		$is_read     = '' !== $is_read_raw ? (bool) $is_read_raw : null;

		// "Date: Wed, 30 Jul 2025 03:38:07 +0000";
		$date_header = $message->getHeader( 'Date' );
		$date_header = str_replace( 'Date: ', '', $date_header );
		// 29 May 2026 06:36:13 -0700
		$sent_at = DateTime::createFromFormat( DateTime::RFC2822, $date_header ) ?: null;

		$attachment_ids = get_post_meta( $post_id, 'attachment_ids', true );
		$attachment_ids = (array) json_decode( $attachment_ids );

		return new BH_Email(
			post_id: $post_id,
			post_type: $post->post_type,
			imessage: $message,
			message_id: $message->getMessageId(),
			subject: $post->post_title,
			from_email: $from_email,
			from_name: $from_name,
			original_mime_message: $post->post_content,
			body_plain_text: $message->getTextContent(),
			body_html: $message->getHtmlContent(),
			attachment_ids: $attachment_ids,
			sent_at: $sent_at,
			downloaded_at: new DateTime( $post->post_date, new DateTimeZone( 'UTC' ) ),
			last_updated: new DateTime( $post->post_modified, new DateTimeZone( 'UTC' ) ),
			post_status: $post->post_status,
			is_remote_read: $is_read,
			is_remote_deleted: $is_read,
		);
	}
}

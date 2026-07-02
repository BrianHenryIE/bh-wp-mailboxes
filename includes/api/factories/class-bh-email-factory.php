<?php
/**
 * Factory for creating BH_Email instances from WordPress posts.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Factories;

use BrianHenryIE\WP_Mailboxes\API\Model\BH_Email;
use BrianHenryIE\WP_Mailboxes\API\Model\Remote_Email_Coordinates;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use WP_Post;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Factory for BH_Email objects.
 */
class BH_Email_Factory {
	use LoggerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		LoggerInterface $logger,
	) {
		$this->setLogger( $logger );
	}

	/**
	 * Hydrates a BH_Email from a WP_Post.
	 *
	 * @param WP_Post $post The WordPress post to hydrate from.
	 */
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

		$post_id     = $post->ID;
		$post_parent = $post->post_parent;

		$is_read_raw = get_post_meta( $post_id, 'is_remote_read', true );
		switch ( $is_read_raw ) {
			case 'yes':
				$is_remote_read = true;
				break;
			case 'no':
				$is_remote_read = false;
				break;
			default:
				$is_remote_read = null;
		}
		unset( $is_read_raw );
		$is_deleted_raw = get_post_meta( $post_id, 'is_remote_deleted', true );
		switch ( $is_deleted_raw ) {
			case 'yes':
				$is_remote_deleted = true;
				break;
			case 'no':
				$is_remote_deleted = false;
				break;
			default:
				$is_remote_deleted = null;
		}
		unset( $is_deleted_raw );

		// "Date: Wed, 30 Jul 2025 03:38:07 +0000";
		$date_header = str_replace( 'Date: ', '', (string) $message->getHeader( 'Date' ) );
		// 29 May 2026 06:36:13 -0700
		$sent_at_result = DateTime::createFromFormat( DateTimeInterface::RFC2822, $date_header );
		$sent_at        = ( false !== $sent_at_result ) ? $sent_at_result : null;

		// Absent meta means attachment-saving was disabled (null); a present value (even `[]`) means
		// it was enabled. This distinction drives the "Attachments disabled" vs "No attachments" UI.
		$attachment_ids_raw = get_post_meta( $post_id, 'attachment_ids', true );
		$attachment_ids     = ! is_string( $attachment_ids_raw ) || empty( $attachment_ids_raw )
			? null
			: array_filter(
				(array) json_decode( $attachment_ids_raw ),
				fn( $value ) => is_int( $value )
			);

		$remote_uid          = get_post_meta( $post_id, 'remote_uid', true );
		$remote_folder       = get_post_meta( $post_id, 'remote_folder', true );
		$remote_uid_validity = get_post_meta( $post_id, 'remote_uid_validity', true );
		$remote_coordinates  = new Remote_Email_Coordinates(
			message_id: $message->getMessageId() ?? '',
			remote_uid: is_string( $remote_uid ) && ! empty( $remote_uid ) ? $remote_uid : null,
			folder: is_string( $remote_folder ) && ! empty( $remote_folder ) ? $remote_folder : null,
			uid_validity: is_numeric( $remote_uid_validity ) ? (int) $remote_uid_validity : null,
		);

		return new BH_Email(
			post_id: $post_id,
			post_type: $post->post_type,
			email_account_local_id: (int) $post_parent,
			imessage: $message,
			message_id: $message->getMessageId() ?? '',
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
			local_status: $post->post_status,
			is_remote_read: $is_remote_read,
			is_remote_deleted: $is_remote_deleted,
			remote_coordinates: $remote_coordinates,
		);
	}
}

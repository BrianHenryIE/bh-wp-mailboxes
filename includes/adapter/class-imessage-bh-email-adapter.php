<?php
/**
 * Converts a parsed MIME IMessage into a BH_Email domain object.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Adapter;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use DateTime;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;

class IMessage_BH_Email_Adapter {

	/**
	 * Build a BH_Email from a parsed MIME message.
	 *
	 * @param IMessage $message              The parsed MIME message.
	 * @param string   $cpt_name            The custom post type to save as.
	 * @param int      $mailbox_term_id     The mailbox taxonomy term ID.
	 */
	public static function adapt( IMessage $message, string $cpt_name ): BH_Email {

		$attachment_parts      = $message->getAllAttachmentParts();
		$all_parts             = $message->getAllParts();
		$non_attachment_parts  = array_filter(
			$all_parts,
			fn( $part ) => ! in_array( $part, $attachment_parts, true )
		);
		$original_email_string = implode( ' ', $non_attachment_parts );

		$headers = array();
		foreach ( $message->getAllHeaders() as $header ) {
			$value = $header->getValue();
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$headers[ $header->getName() ] = $value;
			}
		}

		$from_header = $message->getHeader( 'From' );
		$from_email  = '';
		$from_name   = null;
		if ( $from_header instanceof AddressHeader ) {
			$from_email = $from_header->getEmail() ?? '';
			$person     = $from_header->getPersonName();
			$from_name  = ( '' !== (string) $person ) ? $person : null;
		}

		$downloaded_at = null;
		$date_value    = $message->getHeaderValue( 'Date' );
		if ( ! is_null( $date_value ) ) {
			$parsed = date_create( $date_value );
			if ( false !== $parsed ) {
				$downloaded_at = $parsed;
			}
		}

		// Downloaded at.

		return new BH_Email(
			post_type:           $cpt_name,
			email_id:            $message->getMessageId() ?? '',
			subject:             $message->getSubject() ?? '',
			from_email:          $from_email,
			from_name:           $from_name,
			headers:             $headers,
			downloaded_at:       $downloaded_at,
		);
	}
}

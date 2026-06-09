<?php

namespace BrianHenryIE\WP_Mailboxes\Repository\Factories;

use BrianHenryIE\WP_Mailboxes\Adapter\IMessage_BH_Email_Adapter;
use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
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
		if ( $from_header instanceof AddressHeader ) {
			$from_email = $from_header->getEmail() ?? '';
			$person     = $from_header->getPersonName();
			$from_name  = ( '' !== (string) $person ) ? $person : null;
		}

		$post_id = $post->ID;

		$is_read_raw = get_post_meta( $post_id, 'is_read_remote', true );
		$is_read     = '' !== $is_read_raw ? (bool) $is_read_raw : null;

		return new BH_Email(
			post_id: $post_id,
			post_type: $post->post_type,
			email_id: $message->getMessageId(),
			subject: $post->post_title,
			from_email: $from_email,
			from_name: $from_name,
			body_plain_text: $message->getTextContent(),
			body_html: $message->getHtmlContent(),
			post_status: $post->post_status,
			is_remote_read: $is_read,
			is_remote_deleted: $is_read,
			imessage: $message
		);
	}
}

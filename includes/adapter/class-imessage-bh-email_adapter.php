<?php
/**
 * Constructor to build a BH_Email from a parsed MIME IMessage.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Adapter;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;

/**
 * Constructs a BH_Email from a parsed MIME IMessage.
 */
class IMessage_BH_Email_Adapter extends BH_Email {

	/**
	 * @param IMessage $message     The parsed MIME message.
	 * @param string   $cpt_name         The custom post type to save as.
	 * @param int      $mailbox_category_term_id The mailbox taxonomy term ID.
	 */
	public function __construct( IMessage $message, string $cpt_name, int $mailbox_category_term_id ) {

		// getAllHeaders() returns IHeader[] — extract name => value string pairs.
		$new_email_headers = array();
		foreach ( $message->getAllHeaders() as $header ) {
			$value = $header->getValue();
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$new_email_headers[ $header->getName() ] = $value;
			}
		}

		$from_header = $message->getHeader( 'From' );
		$from_email  = '';
		$from_name   = '';
		if ( $from_header instanceof AddressHeader ) {
			$from_email = $from_header->getEmail() ?? '';
			$from_name  = $from_header->getPersonName() ?? '';
		}

		$this->post_type           = $cpt_name;
		$this->account_category_id = $mailbox_category_term_id;

		$this->email_id        = $message->getMessageId() ?? '';
		$this->headers         = $new_email_headers;
		$this->subject         = $message->getSubject() ?? '';
		$this->from_email      = $from_email;
		$this->from_name       = $from_name;
		$this->body_plain_text = $message->getTextContent() ?? '';
		$this->body_html       = $message->getHtmlContent() ?? '';
		$this->meta_data       = array();
	}
}

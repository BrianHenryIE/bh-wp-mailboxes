<?php
/**
 * Constructor to parse an ImapEngine email into a BH_Email.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Imap;

use BrianHenryIE\WP_Mailboxes\BH_Email;
use ImapEngine\Imap\Message\BasicMessageInterface;

/**
 * Just a constructor for BH_Email.
 */
class ImapEngine_BH_Email extends BH_Email {

	/**
	 * Construct a BH_Email object.
	 *
	 * TODO: This may also need to take `iterable<ImapEngine\Imap\Message\PartInterface>`
	 *
	 * @param BasicMessageInterface $imapengine_email The email object fetched by the library.
	 * @param string                $cpt The custom post type this email is being saved as.
	 * @param int                   $mailbox_category_term_id The mailbox id for the email.
	 */
	public function __construct( BasicMessageInterface $imapengine_email, string $cpt, int $mailbox_category_term_id ) {

		$email_headers     = $imapengine_email->getHeaders();
		$new_email_headers = array();

		foreach ( $email_headers as $header_name => $header_content ) {

			/**
			 * ImapEngine parses some headers into objects.
			 * e.g. `BH <bh@domain.com>` into an object
			 * and has some flags that are often empty, and not actually the headers.
			 * We just want the headers as strings.
			 */
			if ( ( is_string( $header_content ) || is_numeric( $header_content ) )
				&& ! empty( trim( $header_content ) )
			) {
				$new_email_headers[ $header_name ] = $header_content;
			}
		}

		$this->post_type           = $cpt;
		$this->account_category_id = $mailbox_category_term_id;

		$this->email_id        = $imapengine_email->getId();
		$this->headers         = $new_email_headers;
		$this->subject         = $imapengine_email->getSubject() ?? '';
		$this->from_email      = $imapengine_email->getFrom()->getAddress();
		$this->from_name       = $imapengine_email->getFrom()->getName();
		$this->body_plain_text = $imapengine_email->getBodyText() ?? '';
		$this->body_html       = $imapengine_email->getBodyHtml() ?? '';
		$this->meta_data       = array();
	}
}

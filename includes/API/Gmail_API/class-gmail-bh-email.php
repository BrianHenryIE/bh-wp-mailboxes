<?php
/**
 * A constructor to parse the email fetched by the Google SDK into a BH_Email.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;

class Gmail_BH_Email extends BH_Email {

	/**
	 * Create a new BH_Email from a newly downloaded email.
	 *
	 * @param array{cpt:string, headers:array, from_email:string, from_name?:string, subject:string, body_text:string, body_html:string} $new_email
	 */
	public function __construct( array $new_email ) {

		$this->post_type = $new_email['cpt'];

		$this->account_category_id = $new_email['account_category_id'];

		$this->email_id        = $new_email['email_id'];
		$this->headers         = $new_email['headers'];
		$this->subject         = $new_email['subject'];
		$this->from_email      = $new_email['from_email'];
		$this->from_name       = $new_email['from_name'];
		$this->body_plain_text = $new_email['body_text'];
		$this->body_html       = $new_email['body_html'];
		$this->meta_data       = $new_email['meta_data'];
	}
}

<?php
/**
 * Adapter to build a BH_Email from a Gmail API-fetched message array.
 *
 * @deprecated Gmail_Email_Fetcher now returns IMessage objects via ZImessage_Collection.
 *             Use IMessage_BH_Email_Adapter::adapt() instead.
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\Providers\Gmail_API;

use BrianHenryIE\WP_Mailboxes\Model\BH_Email;

/**
 * Deprecated Gmail email adapter — now superseded by IMessage_BH_Email_Adapter.
 *
 * @deprecated
 */
class Gmail_BH_Email extends BH_Email {

	/**
	 * Constructor.
	 *
	 * @param array{cpt:string, account_category_id:int, email_id:string, headers:array<string,string>, subject:string, from_email:string, from_name:?string, body_text:string, body_html:string, meta_data:array<string,mixed>} $new_email Email data array.
	 */
	public function __construct( array $new_email ) {
		parent::__construct(
			post_type:           $new_email['cpt'],
			account_category_id: $new_email['account_category_id'],
			email_id:            $new_email['email_id'],
			subject:             $new_email['subject'],
			from_email:          $new_email['from_email'],
			from_name:           $new_email['from_name'] ?? null,
			body_plain_text:     $new_email['body_text'],
			body_html:           $new_email['body_html'],
			headers:             $new_email['headers'],
			meta_data:           $new_email['meta_data'],
		);
	}
}

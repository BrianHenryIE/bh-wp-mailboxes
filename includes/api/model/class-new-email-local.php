<?php
/**
 * When firing the action, we wrap emails in this class so consumers have an easy way to invoke functions on the email.
 *
 * When invoking methods on this immutable class, always use the result in future calls (fluent).
 *
 * @package brianhenryie/bh-wp-mailboxes
 */

namespace BrianHenryIE\WP_Mailboxes\API\Model;

use BrianHenryIE\WP_Mailboxes\API\API_Interface;
use BrianHenryIE\WP_Mailboxes\API\New_Email_Interface;
use BrianHenryIE\WP_Mailboxes\Email_Account_Settings_Interface;

/**
 * A downloaded email wrapper offering local-only operations: status changes, log notes, and trashing.
 */
readonly class New_Email_Local implements New_Email_Interface {

	/**
	 * Constructor.
	 *
	 * @param BH_Email      $email POJO.
	 * @param API_Interface $api API.
	 */
	public function __construct(
		protected BH_Email $email,
		protected API_Interface $api,
	) {
	}

	/**
	 * Get the immutable email itself.
	 */
	public function get_email(): BH_Email {
		return $this->email;
	}

	/**
	 * Set the post_status – 'processed' when the email was expected and handled, 'saved' when expected, handled, and should be preserved.
	 *
	 * Emails not set to 'saved' will be deleted per the {@see Email_Account_Settings_Interface::get_delete_emails_days()} config.
	 *
	 * Consumers should endeavor to also ::add_local_note() on every change (the primary point of this library is to add visibility into email processing for debugging).
	 *
	 * @param string $local_status 'new'|'processed'|'saved'.
	 */
	public function update_local_status( string $local_status = 'processed' ): self {
		return new self(
			email: $this->api->update_email_local_status( email: $this->email, local_status: $local_status ),
			api: $this->api,
		);
	}

	/**
	 * Add a note that will be displayed on the single email view's log.
	 *
	 * E.g. "email did not match any Venmo payment email regex".
	 *
	 * @param string              $message Message text. (hrefs should be possible, not sure what else yet – `wp_kses_post()`?).
	 * @param string              $level   'info'|'notice'|'warning'|error'.
	 * @param array<string,mixed> $context Arbitrary serializable data.
	 */
	public function add_local_note( string $message, string $level = 'notice', array $context = array() ): self {
		$this->api->insert_email_log_note( post_id: $this->email->post_id, message: $message, level: $level );

		return new self(
			email: $this->email,
			api: $this->api,
		);
	}

	/**
	 * Trash the local email immediately.
	 *
	 * Antithetical to logging the emails, but available to the consumers.
	 *
	 * TODO: make sure comments and attachments get deleted too.
	 * TODO: also don't use wp_delete_post here.
	 */
	public function trash_locally(): self {
		wp_delete_post( $this->email->post_id );

		return new self(
			email: $this->email,
			api: $this->api,
		);
	}
}
